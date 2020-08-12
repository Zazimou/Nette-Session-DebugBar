<?php

namespace Kdyby\SessionPanel\DI;

use Kdyby\SessionPanel\Diagnostics\SessionPanel;
use Nette\Configurator;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\PhpGenerator\ClassType;


/**
 * @author Jáchym Toušek <enumag@gmail.com>
 */
class SessionPanelExtension extends CompilerExtension
{

    public function loadConfiguration()
    {
        $builder = $this->getContainerBuilder();
        if ($builder->parameters['debugMode']) {
            $builder->addDefinition($this->prefix('panel'))
                ->setFactory(SessionPanel::class);
        }
    }

    public function afterCompile(ClassType $class)
    {
        $builder = $this->getContainerBuilder();
        if ($builder->parameters['debugMode']) {
            $class->methods['initialize']->addBody(
                'Kdyby\SessionPanel\Diagnostics\SessionPanel::register($this->getService(?));',
                [$this->prefix('panel')]
            );
        }
    }

    public static function register(Configurator $configurator)
    {
        $configurator->onCompile[] = function(Compiler $compiler) {
            $compiler->addExtension('debugger.session', new SessionPanelExtension);
        };
    }

}
