<?php

namespace Valet;

use DomainException;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class PhpFpm
{
    public $pm;
    public $sm;
    public $cli;
    public $files;
    public $version;

    /**
     * Create a new PHP FPM class instance.
     *
     * @param PackageManager $pm
     * @param ServiceManager $sm
     * @param CommandLine    $cli
     * @param Filesystem     $files
     * @return void
     */
    public function __construct(PackageManager $pm, ServiceManager $sm, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->files = $files;
        $this->version = $this->getVersion();
    }

    /**
     * Install and configure PHP FPM.
     *
     * @return void
     */
    public function install()
    {
        if (!$this->pm->installed("php{$this->version}-fpm")) {
            $this->pm->ensureInstalled("php{$this->version}-fpm");
            $this->sm->enable($this->fpmServiceName());
        }

        $this->files->ensureDirExists('/var/log', user());

        $this->installConfiguration();

        $this->restart();
    }

    /**
     * Uninstall PHP FPM valet config.
     *
     * @return void
     */
    public function uninstall()
    {
        if ($this->files->exists('/etc/systemd/system/php-fpm.service.d/valet.conf')) {
            unlink('/etc/systemd/system/php-fpm.service.d/valet.conf');
        }

        if ($this->files->exists($this->fpmConfigPath() . '/valet.conf')) {
            $this->files->unlink($this->fpmConfigPath() . '/valet.conf');
            $this->stop();
        }
    }

    /**
     * Change the php-fpm version.
     *
     * @param string|float|int $version
     *
     * @return void
     */
    public function changeVersion($version = null)
    {
        $oldVersion = $this->version;
        $exception = null;

        $this->stop();
        info('Disabling php' . $this->version . '-fpm...');
        $this->sm->disable($this->fpmServiceName());

        if (!isset($version) || strtolower($version) === 'default') {
            $this->version = $this->getVersion(true);
        } else {
            $this->version = $version;
        }

        try {
            $this->install();
        } catch (DomainException $e) {
            $this->version = $oldVersion;
            $exception = $e;
        }

        if ($this->sm->disabled($this->fpmServiceName())) {
            info('Enabling php' . $this->version . '-fpm...');
            $this->sm->enable($this->fpmServiceName());
        }

        if ($this->version !== $this->getVersion(true)) {
            $this->files->putAsUser(VALET_HOME_PATH . '/use_php_version', $this->version);
        } else {
            $this->files->unlink(VALET_HOME_PATH . '/use_php_version');
        }

        if ($exception) {
            info('Changing version failed');
            throw $exception;
        }
    }

    /**
     * Update the PHP FPM configuration to use the current user.
     *
     * @return void
     */
    public function installConfiguration()
    {
        $contents = $this->files->get(__DIR__ . '/../stubs/fpm.conf');

        $this->files->putAsUser(
            $this->fpmConfigPath() . '/valet.conf',
            str_array_replace([
                'VALET_USER' => user(),
                'VALET_GROUP' => group(),
                'VALET_HOME_PATH' => VALET_HOME_PATH,
            ], $contents)
        );

        if (($this->sm) instanceof \Valet\ServiceManagers\Systemd) {
            $this->systemdDropInOverride();
        }
    }

    /**
     * Install Drop-In systemd override for php-fpm service
     *
     * @return void
     */
    public function systemdDropInOverride()
    {
        $this->files->ensureDirExists('/etc/systemd/system/php-fpm.service.d');
        $this->files->putAsUser(
            '/etc/systemd/system/php-fpm.service.d/valet.conf',
            $this->files->get(__DIR__ . '/../stubs/php-fpm.service.d/valet.conf')
        );
    }

    /**
     * Restart the PHP FPM process.
     *
     * @return void
     */
    public function restart()
    {
        $this->sm->restart($this->fpmServiceName());
    }

    /**
     * Stop the PHP FPM process.
     *
     * @return void
     */
    public function stop()
    {
        $this->sm->stop($this->fpmServiceName());
    }

    /**
     * PHP-FPM service status.
     *
     * @return void
     */
    public function status()
    {
        $this->sm->printStatus($this->fpmServiceName());
    }

    /**
     * Get installed PHP version.
     *
     * @param string $real force getting version from /usr/bin/php.
     *
     * @return string
     */
    public function getVersion($real = false)
    {
        if (!$real && $this->files->exists(VALET_HOME_PATH . '/use_php_version')) {
            $version = $this->files->get(VALET_HOME_PATH . '/use_php_version');
        } else {
            $version = explode('php', basename($this->files->readLink('/usr/bin/php')))[1];
        }

        return $version;
    }

    /**
     * Determine php service name
     *
     * @return string
     */
    public function fpmServiceName($serviceName = null)
    {
        if($serviceName === null){
            $service = "php{$this->version}-fpm";
        }else{
            $service = $serviceName;
        }
        $status = $this->sm->status($service);
        if (strpos($status, 'not-found') || strpos($status, 'not be found')) {
                $secondTry = $this->fpmServiceName("php-fpm{$this->version}");
                if($secondTry instanceof DomainException){
                    return new DomainException("Unable to determine PHP service name.");
                }

                return $secondTry;
        }

        return $service;
    }

    /**
     * Get the path to the FPM configuration file for the current PHP version.
     *
     * @return string
     */
    public function fpmConfigPath()
    {
        return collect([
            '/etc/php/' . $this->version . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $this->version . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $this->version . '/php-fpm.d', // Manjaro
            '/etc/php-fpm.d', // Fedora
            '/etc/php/php-fpm.d', // Arch
            '/etc/php7/fpm/php-fpm.d', // openSUSE
        ])->first(function ($path) {
            return is_dir($path);
        }, function () {
            throw new DomainException('Unable to determine PHP-FPM configuration folder.');
        });
    }
}
