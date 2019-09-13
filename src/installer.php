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
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extensionmanager\Utility\InstallUtility;
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
                    $platformConfigFolder = Environment::getConfigPath() . '/platform-temp-bridge';
                    @mkdir($platformConfigFolder);
                    $typo3confFolder = Environment::getLegacyConfigPath();
                    // Remove old files if they are not linked
                    if (!is_link($typo3confFolder . '/LocalConfiguration.php')) {
                        @unlink($platformConfigFolder . '/LocalConfiguration.php');
                    }
                    if (!is_link($typo3confFolder . '/PackageStates.php')) {
                        @unlink($platformConfigFolder . '/PackageStates.php');
                    }
                    rename($typo3confFolder . '/LocalConfiguration.php', $platformConfigFolder . '/LocalConfiguration.php');
                    rename($typo3confFolder . '/PackageStates.php', $platformConfigFolder . '/PackageStates.php');
                    symlink(Environment::getVarPath() . '/LocalConfiguration.php', $typo3confFolder . '/LocalConfiguration.php');
                    symlink(Environment::getVarPath() . '/PackageStates.php', $typo3confFolder . '/PackageStates.php');
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

/**
 * This is called in the post-deploy hook to ensure that the symlinks are actually valid
 */
class WireConfigFoldersCommand extends TYPO3InstallerCommand
{
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $platformConfigFolder = Environment::getConfigPath() . '/platform-temp-bridge';
        if (!file_exists(Environment::getVarPath() . '/PackageStates.php')) {
            copy($platformConfigFolder . '/PackageStates.php', Environment::getVarPath() . '/PackageStates.php');
        }
        if (!file_exists(Environment::getVarPath() . '/LocalConfiguration.php')) {
            copy($platformConfigFolder . '/LocalConfiguration.php', Environment::getVarPath() . '/LocalConfiguration.php');
        }
        return 0;
    }
}

/**
 * Create all necessary database tables and populate them with content, if an extension has shipped some content
 */
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

        // Also try to import all contents of the packages, for this to work we need to fully boot TYPO3
        $packageManager = $this->container->get(PackageManager::class);
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $installUtility = $objectManager->get(InstallUtility::class);
        foreach ($packageManager->getActivePackages() as $package) {
            try {
                $installUtility->processExtensionSetup($package->getPackageKey());
            } catch (\Throwable $e) {
                $io->error('Seems like there was an error importing data from extension ' . $package . '. Try re-installing via Extenison Manager.');
            }
        }

        return 0;
    }
}

/**
 * Creates a new administrator user to log into TYPO3
 */
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
        $databaseConnection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_users');
        if ($databaseConnection->select(['*'], 'be_users', ['username' => $username, 'deleted' => 0])->rowCount()) {
            $io->error('An administrator named "' . $username . '" already exists. Wont do');
            return 1;
        }
        // Insert admin user
        $adminUserFields = [
            'username' => $username,
            'password' => $this->getHashedPassword($password),
            'admin' => 1,
            'tstamp' => time(),
            'crdate' => time()
        ];
        try {
            $databaseConnection->insert('be_users', $adminUserFields);
            $adminUserUid = (int)$databaseConnection->lastInsertId('be_users');
        } catch (DBALException $exception) {
            $io->error('Administrator account not created: ' . $exception->getPrevious()->getMessage());
            return 1;
        }
        // Set password as install tool password, add admin user to system maintainers
        $existingMaintainers = $GLOBALS['TYPO3_CONF_VARS']['SYS']['systemMaintainers'] ?? [];
        $existingMaintainers[] = $adminUserUid;
        $configurationManager = $this->container->get(ConfigurationManager::class);
        $configurationManager->setLocalConfigurationValuesByPathValuePairs([
            'BE/installToolPassword' => $this->getHashedPassword($password),
            'SYS/systemMaintainers' => $existingMaintainers
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

// Here goes the spaghetti code

// Bootstrap TYPO3
SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_INSTALL);
$container = Bootstrap::init($classLoader);

$application = new Application('platform.sh TYPO3 Installer');
$application->add(new FileAndFolderSetupCommand($container, 'install:setup'));
$application->add(new WireConfigFoldersCommand($container, 'install:wireconfig'));
// Only run these if the base set up is run through
if (@file_exists(Environment::getLegacyConfigPath() . '/LocalConfiguration.php')) {
    $application->add(new ImportDatabaseCommand($container, 'install:dbimport'));
    $application->add(new CreateAdminUser($container, 'install:createuser'));
} else {
    // Remove cache folder manually, always a good idea
    GeneralUtility::rmdir(Environment::getVarPath() . '/cache', true);
    $application->setDefaultCommand('install:setup');
}
$application->run();