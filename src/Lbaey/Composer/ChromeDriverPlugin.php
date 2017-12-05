<?php

namespace Lbaey\Composer;

/*
 * This file is part of chromedriver composer plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use Composer\Cache;
use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Package\CompletePackage;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Composer\Util\RemoteFilesystem;
use Composer\Util\ProcessExecutor;

/**
 * @author Laurent Baey <laurent.baey@gmail.com>
 */
class ChromeDriverPlugin implements PluginInterface, EventSubscriberInterface
{
    const UNKNOWN = 'unknown';
    const LINUX32 = 'linux32';
    const LINUX64 = 'linux64';
    const MAC64 = 'mac64';
    const WIN32 = 'win32';

    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;
    
    /**
     * @var Cache
     */
    protected $cache;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallCmd',
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdateCmd',
        );
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->config = $this->composer->getConfig();
        $this->cache = new Cache(
            $this->io,
            implode(DIRECTORY_SEPARATOR, [
                $this->config->get('cache-dir'),
                'files',
                'lbaey-chromedriver',
                'downloaded-bin'
            ])
        );
    }

    /**
     * Handle post install command events.
     *
     * @param Event $event The event to handle.
     *
     */
    public function onPostInstallCmd(Event $event)
    {
        $this->installDriver($event);
    }

    /**
     * Handle post update command events.
     *
     * @param Event $event The event to handle.
     *
     */
    public function onPostUpdateCmd(Event $event)
    {
        $this->installDriver($event);
    }

    /**
     * @param Event $event
     */
    protected function installDriver(Event $event)
    {
        $extra = null;
        $extra = $this->composer->getPackage()->getExtra();

        if (empty($extra['lbaey/chromedriver']['chromedriver-version'])) {
            $version = '2.33';
        } else {
            $version = $extra['lbaey/chromedriver']['chromedriver-version'];
        }

        $platformType = $this->getPlatform();

        $executableName = $this->getExecutableFileName();

        $chromeDriverPath = $this->config->get('bin-dir') . DIRECTORY_SEPARATOR . $executableName;
        $output = '';

        if (file_exists($chromeDriverPath) && is_executable($chromeDriverPath)) {
            $processExecutor = new ProcessExecutor($this->io);
            $processExecutor::setTimeout(10);
            $processExecutor->execute($chromeDriverPath . ' --version', $output);

            // right version? => nothing to do
            if (strpos($output, 'ChromeDriver ' . $version) === 0) {
                $this->io->write(sprintf('The right version %s of ChromeDriver is already installed', $version));
                return;
            }
        }

        $fs = new Filesystem();
        $fs->ensureDirectoryExists($this->cache->getRoot() . $version);
        $fs->ensureDirectoryExists($this->config->get('bin-dir'));

        $chromeDriverArchiveCacheFileName = $this->cache->getRoot() . $version . DIRECTORY_SEPARATOR . $executableName;

        if (!$this->cache->isEnabled() || !file_exists($chromeDriverArchiveCacheFileName)) {
            $this->io->write(sprintf(
                "Downloading ChromeDriver version %s for %s",
                $version,
                $this->getPlatformNames()[$platformType]
            ));

            $chromeDriverOriginUrl = "https://chromedriver.storage.googleapis.com";

            /** @var RemoteFilesystem $remoteFileSystem */

            $fileOrigin = $chromeDriverOriginUrl . '/' . $version . '/' . $this->getRemoteFileName();

            file_put_contents(
                $chromeDriverArchiveCacheFileName,
                fopen($fileOrigin, 'r')
            );
        } else {
            $this->io->write(sprintf(
                'Using cached version of %s',
                $this->getRemoteFileName()
            ));
        }

        $archive = new \ZipArchive();
        $archive->open($chromeDriverArchiveCacheFileName);
        $archive->extractTo($this->config->get('bin-dir'));

        if ($this->getPlatform() !== self::WIN32) {
            chmod($this->config->get('bin-dir') . DIRECTORY_SEPARATOR . $executableName, 0755);
        }
    }

    /**
     *
     */
    protected function getPlatform()
    {
        if (stripos(PHP_OS, 'win') === 0) {
            return self::WIN32;
        } elseif (stripos(PHP_OS, 'darwin') === 0) {
            return self::MAC64;
        } elseif (stripos(PHP_OS, 'linux') === 0) {
            if (PHP_INT_SIZE === 8) {
                return self::LINUX64;
            } else {
                return self::LINUX32;
            }
        }

        $this->io->writeError('Could not guess your platform, download chromedriver manually.');

        return null;
    }

    /**
     * @return string
     * @throws \Exception
     */
    protected function getRemoteFileName()
    {
        switch ($this->getPlatform()) {
            case self::LINUX32:
                return "chromedriver_linux32.zip";
            case self::LINUX64:
                return "chromedriver_linux64.zip";
            case self::MAC64:
                return "chromedriver_mac64.zip";
            case self::WIN32:
                return "chromedriver_win32.zip";
            default:
                throw new \Exception('Platform is not set.');
        }
    }

    /**
     * @return string
     * @throws \Exception
     */
    protected function getExecutableFileName()
    {
        switch ($this->getPlatform()) {
            case self::LINUX32:
            case self::LINUX64:
            case self::MAC64:
                return 'chromedriver';
            case self::WIN32:
                return 'chromedriver.exe';
            default:
                throw new \Exception('Platform is not set.');
        }
    }

    /**
     * @return array
     */
    protected function getPlatformNames()
    {
        return [
            self::LINUX32 => 'Linux 32Bits',
            self::LINUX64 => 'Linux 64Bits',
            self::MAC64 => 'Mac OS X',
            self::WIN32 => 'Windows'
        ];
    }
}
