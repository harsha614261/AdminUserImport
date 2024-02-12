<?php
declare(strict_types=1);

namespace Harsha\AdminUserImport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\ImportExport\Helper\Data as ImportHelper;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\Entity\AbstractEntity;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\ResourceModel\Helper;
use Magento\ImportExport\Model\ResourceModel\Import\Data;
use Psr\Log\LoggerInterface;

/**
 * Class for import functionality for admin users
 */
class AdminUsers extends AbstractEntity
{
    /**
     * Constant for table name
     */
    const TABLE = 'admin_user';

    /**
     * Constant for entity name
     */
    const ADMIN_USERS_ENTITY = 'admin_users';

    /**
     * Constant for primary key in the database table
     */
    const ENTITY_ID_COLUMN = 'user_id';

    /**
     * Valid column names
     */
    protected $validColumnNames = [
        'firstname',
        'lastname',
        'email',
        'password',
        'username'
    ];

    /**
     * Constant for authorization_role database name
     */
    private const AUTHORIZATION_ROLE = 'authorization_role';

    /**
     * @var AdapterInterface
     */
    protected AdapterInterface $connection;

    /**
     * Constructor Method
     *
     * @param JsonHelper $jsonHelper
     * @param ImportHelper $importExportData
     * @param Data $importData
     * @param ResourceConnection $resource
     * @param Helper $resourceHelper
     * @param ProcessingErrorAggregatorInterface $errorAggregator
     * @param EncryptorInterface $encryptor
     * @param LoggerInterface $logger
     */
    public function __construct(
        JsonHelper $jsonHelper,
        ImportHelper $importExportData,
        Data $importData,
        ResourceConnection $resource,
        Helper $resourceHelper,
        ProcessingErrorAggregatorInterface $errorAggregator,
        EncryptorInterface $encryptor,
        LoggerInterface $logger
    ) {
        $this->jsonHelper = $jsonHelper;
        $this->_importExportData = $importExportData;
        $this->_resourceHelper = $resourceHelper;
        $this->_dataSourceModel = $importData;
        $this->resource = $resource;
        $this->connection = $resource->getConnection(ResourceConnection::DEFAULT_CONNECTION);
        $this->errorAggregator = $errorAggregator;
        $this->encryptor = $encryptor;
        $this->logger = $logger;
        $this->initMessageTemplates();
    }

    /**
     * Import data method for import and delete behaviors
     *
     * @return bool
     */
    protected function _importData(): bool
    {
        if (Import::BEHAVIOR_DELETE === $this->getBehavior() || Import::BEHAVIOR_APPEND === $this->getBehavior()) {
            $this->saveAndDeleteAdminUsers();
        }
        return true;
    }

    /**
     * Get available columns
     *
     * @return array
     */
    public function getValidColumnNames(): array
    {
        return $this->validColumnNames;
    }

    /**
     * Get available columns
     *
     * @return array
     */
    private function getAvailableColumns(): array
    {
        return $this->validColumnNames;
    }

    /**
     * Get Entity Type code getter method
     *
     * @return string
     */
    public function getEntityTypeCode(): string
    {
        return static::ADMIN_USERS_ENTITY;
    }

    /**
     * Validate row method to validate each record
     *
     * @param array $rowData
     * @param $rowNum
     * @return bool
     */
    public function validateRow(array $rowData, $rowNum): bool
    {
        $name = $rowData['firstname'] ?? '';
        $lastname = $rowData['lastname'] ?? '';
        $email = $rowData['email'] ?? '';
        $password = $rowData['password'] ?? '';
        $userName = $rowData['username'] ?? '';
        if (!$name) {
            $this->addRowError('FirstNameIsRequired', $rowNum);
        }

        if (!$lastname) {
            $this->addRowError('LastNameIsRequired', $rowNum);
        }

        if (!$email) {
            $this->addRowError('EmailIsRequired', $rowNum);
        }

        if (!$password) {
            $this->addRowError('PasswordIsRequired', $rowNum);
        }

        if (!$userName) {
            $this->addRowError('UsernameIsRequired', $rowNum);
        }

        if (isset($this->_validatedRows[$rowNum])) {
            return !$this->getErrorAggregator()->isRowInvalid($rowNum);
        }

        $this->_validatedRows[$rowNum] = true;

        return !$this->getErrorAggregator()->isRowInvalid($rowNum);
    }

    /**
     * Save Admin users method for Importing the Admin users
     *
     * @return void
     */
    public function saveAndDeleteAdminUsers(): void
    {
        $behavior = $this->getBehavior();
        $rows = [];
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $entityList = [];

            foreach ($bunch as $rowNum => $row) {
                if (!$this->validateRow($row, $rowNum)) {
                    continue;
                }
                if ($this->getErrorAggregator()->hasToBeTerminated()) {
                    $this->getErrorAggregator()->addRowToSkip($rowNum);
                    continue;
                }
                $row[static::ENTITY_ID_COLUMN] = null;
                $rowId = $row[static::ENTITY_ID_COLUMN] ?? null;
                $rows[] = $rowId;
                $columnValues = [];
                foreach ($this->getAvailableColumns() as $columnKey) {
                    $columnValues[$columnKey] = $row[$columnKey];
                }
                $entityList[$rowId][] = $columnValues;

                $this->countItemsCreated += (int)!isset($row[static::ENTITY_ID_COLUMN]);
                $this->countItemsUpdated += (int)isset($row[static::ENTITY_ID_COLUMN]);
            }

            if (Import::BEHAVIOR_APPEND === $behavior) {
                $this->saveEntityFinish($entityList);
            } else {
                $this->deleteEntityFinish($entityList);
            }
        }
    }

    /**
     * Save the new records
     *
     * @param array $entityData
     *
     * @return bool
     */
    private function saveEntityFinish(array $entityData): bool
    {
        if ($entityData) {
            $tableName = $this->connection->getTableName(static::TABLE);
            $rows = [];
            $emailRows = [];
            $userNameRows = [];
            foreach ($entityData as $entityRows) {
                foreach ($entityRows as $row) {
                    $rows[] = $row;
                    $emailRows[] = $row['email'] ?? '';
                    $userNameRows[] = $row['username'] ?? '';
                }
            }
            $select = $this->connection->select()->from($tableName, 'email')
                ->where('username IN (?)', $userNameRows)
                ->where('email IN (?)', $emailRows);
            $existingRecords = $this->connection->fetchAll($select);
            $existingEmail = [];
            for ($i = 0; $i < count($existingRecords); $i++) {
                $existingEmail[] = $existingRecords[$i]['email'];
            }
            for ($j = 0; $j < count($rows); $j++) {
                $encryptedPassword = $this->encryptor->encrypt($rows[$j]['password']);
                $rows[$j]['password'] = $encryptedPassword;
                if (in_array($rows[$j]['email'], $existingEmail)) {
                    $rows[$j] = [];
                }
            }
            $rows = array_filter($rows);
            if ($rows) {
                $this->connection->insertMultiple($tableName, $rows);
                $adminRoleData = [];
                foreach ($rows as $row) {
                    $select = $this->connection->select()->from($tableName, 'user_id')
                        ->where('username IN (?)', $row['username'])
                        ->where('email IN (?)', $row['email']);
                    $userData = $this->connection->fetchOne($select);
                    $data['user_id'] = $userData;
                    $data['role_name'] = $row['username'];
                    $data['parent_id'] = 1;
                    $data['tree_level'] = 2;
                    $data['user_type'] = 2;
                    $data['role_type'] = 'U';
                    $adminRoleData[] = $data;
                    $data = [];
                }
                $this->connection->insertMultiple('authorization_role', $adminRoleData);
                return true;
            }
            return true;
        }
        return true;
    }

    /**
     * Delete the existing records
     *
     * @param array $entityData
     * @return bool
     */
    private function deleteEntityFinish(array $entityData): bool
    {
        if ($entityData) {
            $tableName = $this->connection->getTableName(static::TABLE);
            $rows = [];
            $userNameRows = [];
            foreach ($entityData as $entityRows) {
                foreach ($entityRows as $row) {
                    $rows[] = $row;
                    $userNameRows[] = $row['username'];
                }
            }
            $select = $this->connection->select()->from($tableName, 'username')
                ->where('username IN (?)', $userNameRows);
            $existingRecords = $this->connection->fetchAll($select);

            $existingUser = [];
            for ($i = 0; $i < count($existingRecords); $i++) {
                $existingUser[] = $existingRecords[$i]['username'];
            }
            for ($j = 0; $j < count($rows); $j++) {
                if (!in_array($rows[$j]['username'], $existingUser)) {
                    $rows[$j] = [];
                }
            }
            $rows = array_filter($rows);
            if ($rows) {
                $this->connection->delete(
                    $tableName,
                    $this->connection->quoteInto('username' . ' IN (?)', $existingUser)
                );
                $this->connection->delete(
                    self::AUTHORIZATION_ROLE,
                    $this->connection->quoteInto('role_name' . ' IN (?)', $existingUser)
                );
                return true;
            }
            return true;
        }
        return true;
    }

    /**
     * Init Error Messages
     *
     * @return void
     */
    private function initMessageTemplates(): void
    {
        $this->addMessageTemplate(
            'FirstNameIsRequired',
            __('First name cannot be empty.')
        );
        $this->addMessageTemplate(
            'LastNameIsRequired',
            __('Last name cannot be empty.')
        );
        $this->addMessageTemplate(
            'EmailIsRequired',
            __('Email cannot be empty.')
        );
        $this->addMessageTemplate(
            'PasswordIsRequired',
            __('Password cannot be empty.')
        );
        $this->addMessageTemplate(
            'UsernameIsRequired',
            __('Username cannot be empty.')
        );
    }
}
