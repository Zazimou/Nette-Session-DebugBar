<?php

namespace Kdyby\SessionPanel\Diagnostics;

use AppendIterator;
use ArrayIterator;
use Closure;
use Iterator;
use Latte\Runtime\Filters;
use Nette\Http\IRequest;
use Nette\Http\Response;
use Nette\Http\Session;
use Nette\Http\UrlScript;
use Nette\Iterators\Mapper;
use Nette\SmartObject;
use Nette\Utils\DateTime;
use Tracy;
use Tracy\Bar;
use Tracy\Dumper;


/**
 * Nette Debug Session Panel
 * @author Pavel Železný <info@pavelzelezny.cz>
 * @author Filip Procházka <email@filip-prochazka.cz>
 */
class SessionPanel implements Tracy\IBarPanel
{

    use SmartObject;


    const SIGNAL = 'nette-session-panel-delete-session';
    const SECTION_TYPE = 'section-type';
    const NETTE_SESSION = 'nette-session';
    const PHP_SESSION = 'php-session';

    private Session $session;
    private UrlScript $url;


    /**
     * @param Session  $session
     * @param IRequest $httpRequest
     */
    public function __construct(Session $session, IRequest $httpRequest)
    {
        $this->session = $session;
        $this->url = clone $httpRequest->getUrl();
        $this->processSignal($httpRequest);
    }

    /**
     * Html code for DebuggerBar Tab
     * @return string
     */
    public function getTab(): string
    {
        return self::render(__DIR__.'/templates/tab.phtml', [
            'src' => function($file) {
                return Filters::dataStream(file_get_contents($file));
            },
            'esc' => Closure::fromCallable(function($string) {
                return htmlspecialchars($string, ENT_QUOTES);
            }),
        ]);
    }

    /**
     * Html code for DebuggerBar Panel
     * @return string
     */
    public function getPanel()
    {
        $url = $this->url;

        return self::render(__DIR__.'/templates/panel.phtml', [
            'time'           => Closure::fromCallable(get_called_class().'::time'),
            'esc'            => Closure::fromCallable(function($string) {
                return htmlspecialchars($string, ENT_QUOTES);
            }),
            'click'          => Closure::fromCallable(function($variable) {
                return Dumper::toHtml($variable, [Dumper::COLLAPSE => true]);
            }),
            'del'            => function($section = null, $sectionType = null) use ($url) {
                $url = clone $url;
                $query = $url->getQueryParameters();
                $query['do'] = SessionPanel::SIGNAL;
                $query[SessionPanel::SIGNAL] = $section;
                $query[SessionPanel::SECTION_TYPE] = $sectionType;
                $url->withQuery($query);

                return (string)$url;
            },
            'sections'       => $this->createSessionIterator(),
            'sessionMaxTime' => $this->session->getOptions()['gc_maxlifetime'],
        ]);
    }

    /**
     * @param SessionPanel $panel
     * @return SessionPanel
     */
    public static function register(SessionPanel $panel)
    {
        $panel->registerBarPanel(static::getDebuggerBar());

        return $panel;
    }

    /**
     * Registers panel to debugger
     * @param Bar $bar
     */
    public function registerBarPanel(Bar $bar)
    {
        $bar->addPanel($this);
    }

    /**
     * @param string $file
     * @param array  $vars
     * @return string
     */
    public static function render($file, $vars)
    {
        return call_user_func(function() {
            ob_start();
            foreach (func_get_arg(1) as $__k => $__v) {
                $$__k = $__v;
            }
            unset($__k, $__v);
            require func_get_arg(0);

            return ob_get_clean();
        }, $file, $vars);
    }

    /**
     * @param int $seconds
     * @return string
     */
    public static function time($seconds)
    {
        static $periods = ["second", "minute", "hour", "day", "week", "month", "year", "decade"];
        static $lengths = ["60", "60", "24", "7", "4.35", "12", "10"];

        $difference = $seconds > DateTime::YEAR ? time() - $seconds : $seconds;
        for ($j = 0; $difference >= $lengths[$j]; $j++) {
            $difference /= $lengths[$j];
        }
        $multiply = ($difference = round($difference)) != 1;

        return "$difference {$periods[$j]}".($multiply ? 's' : '');
    }

    /**
     * @return Mapper
     */
    protected function createNetteSessionIterator(): Mapper
    {
        $sections = $this->session->getIterator();

        return new Mapper($sections, function($sectionName) {
            $data = $_SESSION['__NF']['DATA'][$sectionName];

            $section = (object)[
                'title'       => $sectionName,
                'data'        => $data,
                'expiration'  => 'inherited',
                'sectionType' => SessionPanel::NETTE_SESSION,
            ];

            $meta = isset($_SESSION['__NF']['META'][$sectionName])
                ? $_SESSION['__NF']['META'][$sectionName]
                : [];

            if (isset($meta['']['T'])) {
                $section->expiration = SessionPanel::time($meta['']['T'] - time());
            } elseif (isset($meta['']['B']) && $meta['']['B'] === true) {
                $section->expiration = 'Browser';
            }

            return $section;
        });
    }

    /**
     * @return Iterator
     */
    protected function createPhpSessionIterator()
    {
        $sections = [];

        if ($this->session->exists()) {
            $this->session->start();

            foreach ($_SESSION as $sectionName => $data) {
                if ($sectionName === '__NF') {
                    continue;
                }

                $sections[] = (object)[
                    'title'       => $sectionName,
                    'data'        => $data,
                    'expiration'  => 'inherited',
                    'sectionType' => SessionPanel::PHP_SESSION,
                ];
            }
        }

        return new ArrayIterator($sections);
    }



    /****************** Registration *********************/

    /**
     * @return AppendIterator
     */
    protected function createSessionIterator()
    {
        $iterator = new AppendIterator;
        $iterator->append($this->createNetteSessionIterator());
        $iterator->append($this->createPhpSessionIterator());

        return $iterator;
    }

    /**
     * @return Bar
     */
    private static function getDebuggerBar(): Bar
    {
        return Tracy\Debugger::getBar();
    }

    /**
     * @param IRequest $httpRequest
     */
    private function processSignal(IRequest $httpRequest)
    {
        if ($httpRequest->getQuery('do') !== self::SIGNAL) {
            return;
        }

        if (!$this->session->isStarted()) {
            $this->session->start();
        }

        if ($section = $httpRequest->getQuery(self::SIGNAL)) {
            if ($httpRequest->getQuery(self::SECTION_TYPE) == self::PHP_SESSION) {
                unset($_SESSION[$section]);
            } elseif ($httpRequest->getQuery(self::SECTION_TYPE) == self::NETTE_SESSION) {
                $this->session->getSection($section)->remove();
            }
        } else {
            $this->session->destroy();
        }

        $query = $httpRequest->getQuery();
        unset($query['do'], $query[self::SIGNAL], $query[self::SECTION_TYPE]);
        $this->url->withQuery($query);

        $response = new Response;
        $response->redirect($this->url);
        exit(0);
    }


}
