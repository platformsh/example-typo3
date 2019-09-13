<?php

$classLoader = require __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DBALException;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Cache\Frontend\NullFrontend;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\PasswordHashing\Argon2iPasswordHash;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Service\DependencyOrderingService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\FolderStructure\DefaultFactory;

class TYPO3InstallerCommand extends Command
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container, string $name = null)
    {
        $this->container = $container;
        parent::__construct($name);
    }

}
class FileAndFolderSetupCommand extends TYPO3InstallerCommand
{
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $folderStructureFactory = new DefaultFactory();
            $folderStructureFactory->getStructure()->fix();
        } catch (\TYPO3\CMS\Install\FolderStructure\Exception $e) {
            // If all is OK, we're good to go.
        }

        $configurationManager = $this->container->get(ConfigurationManager::class);
        if (!@file_exists(Environment::getLegacyConfigPath() . '/LocalConfiguration.php')) {
            $configurationManager->createLocalConfigurationFromFactoryConfiguration();
        } elseif (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'])) {
            // Set a random encryption key
            $randomKey = GeneralUtility::makeInstance(Random::class)->generateRandomHexString(96);
            $configurationManager->setLocalConfigurationValueByPath('SYS/encryptionKey', $randomKey);
        }

        // Create a "uncached" PackageManager
        $dependencyOrderingService = new DependencyOrderingService();
        $packageManager = new class($dependencyOrderingService) extends PackageManager {
            public function initialize()
            {
                if (!@file_exists(Environment::getLegacyConfigPath() . '/PackageStates.php')) {
                    $this->scanAvailablePackages();
                    $this->packageStatesConfiguration['packages'] = array_combine(array_keys($this->packages), array_keys($this->packages));
                    $this->sortActivePackagesByDependencies();
                    $this->sortAndSavePackageStates();
                    // Also add extension settings
                    $extensionConfiguration = new ExtensionConfiguration();
                    $extensionConfiguration->synchronizeExtConfTemplateWithLocalConfigurationOfAllExtensions();
                    // Now move the files to a write-able location
                    $configFolder = Environment::getVarPath() . '/.platform';
                    @mkdir($configFolder);
                    $typo3confFolder = Environment::getLegacyConfigPath();
                    // Remove old files if they are not linked
                    if (!is_link($typo3confFolder . '/LocalConfiguration.php')) {
                        @unlink($configFolder . '/LocalConfiguration.php');
                    }
                    if (!is_link($typo3confFolder . '/PackageStates.php')) {
                        @unlink($configFolder . '/PackageStates.php');
                    }
                    rename($typo3confFolder . '/LocalConfiguration.php', $configFolder . '/LocalConfiguration.php');
                    rename($typo3confFolder . '/PackageStates.php', $configFolder . '/PackageStates.php');
                    symlink($configFolder . '/LocalConfiguration.php', $typo3confFolder . '/LocalConfiguration.php');
                    symlink($configFolder . '/PackageStates.php', $typo3confFolder . '/PackageStates.php');
                } else {
                    parent::initialize();
                    $this->sortAndSavePackageStates();
                }
            }
        };
        $packageManager->injectCoreCache(new NullFrontend('core'));
        $packageManager->initialize();
        return 0;
    }
}

class ImportDatabaseCommand extends TYPO3InstallerCommand
{
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $sqlReader = $this->container->get(SqlReader::class);
        $sqlCode = $sqlReader->getTablesDefinitionString(true);
        $schemaMigrationService = GeneralUtility::makeInstance(SchemaMigrator::class);
        $createTableStatements = $sqlReader->getCreateTableStatementArray($sqlCode);
        $results = $schemaMigrationService->install($createTableStatements);

        // Only keep statements with error messages
        $results = array_filter($results);
        if (count($results) === 0) {
            $insertStatements = $sqlReader->getInsertStatementArray($sqlCode);
            $schemaMigrationService->importStaticData($insertStatements);
        }
        return 0;
    }
}

class CreateAdminUser extends TYPO3InstallerCommand
{
    protected function configure()
    {
        $this
            ->addArgument(
                'username',
                InputArgument::REQUIRED,
                'backend username'
            )
            ->addArgument(
                'password',
                InputArgument::REQUIRED,
                'Backend user password'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');
        $password = $input->getArgument('password');
        // Insert admin user
        $adminUserFields = [
            'username' => $username,
            'password' => $this->getHashedPassword($password),
            'admin' => 1,
            'tstamp' => time(),
            'crdate' => time()
        ];
        $databaseConnection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_users');
        try {
            $databaseConnection->insert('be_users', $adminUserFields);
            $adminUserUid = (int)$databaseConnection->lastInsertId('be_users');
        } catch (DBALException $exception) {
            $io->error('Administrator account not created: ' . $exception->getPrevious()->getMessage());
            return 1;
        }
        // Set password as install tool password, add admin user to system maintainers
        $configurationManager = $this->container->get(ConfigurationManager::class);
        $configurationManager->setLocalConfigurationValuesByPathValuePairs([
            'BE/installToolPassword' => $this->getHashedPassword($password),
            'SYS/systemMaintainers' => [$adminUserUid]
        ]);
        return 0;
    }

    /**
     * This function returns a salted hashed key for new backend user password and install tool password.
     *
     * @param string $password Plain text password
     * @return string Hashed password
     * @throws \LogicException If argon2 is not available
     */
    private function getHashedPassword(string $password): string
    {
        $instance = GeneralUtility::makeInstance(Argon2iPasswordHash::class);
        if ($instance->isAvailable()) {
            return $instance->getHashedPassword($password);
        }
        throw new \LogicException('No suitable hash method found', 1533988846);
    }
}
// Bootstrap TYPO3
SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_INSTALL);

$container = Bootstrap::init($classLoader);
$application = new Application('platform.sh TYPO3 Installer');
$application->add(new FileAndFolderSetupCommand($container, 'install:setup'));
// Only run these if the base set up is run through
if (@file_exists(Environment::getLegacyConfigPath() . '/LocalConfiguration.php')) {
    $application->add(new ImportDatabaseCommand($container, 'install:dbimport'));
    $application->add(new CreateAdminUser($container, 'install:createuser'));
} else {
    // Remove cache folder manually
    GeneralUtility::rmdir(Environment::getVarPath() . '/cache', true);
    $application->setDefaultCommand('install:setup');
}
$application->run();