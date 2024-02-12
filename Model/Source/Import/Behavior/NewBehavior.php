<?php
declare(strict_types=1);

namespace Harsha\AdminUserImport\Model\Source\Import\Behavior;

use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Source\Import\AbstractBehavior;

class NewBehavior extends AbstractBehavior
{

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            Import::BEHAVIOR_APPEND => __('Add'),
            Import::BEHAVIOR_DELETE => __('Delete'),
        ];
    }

    /**
     * @return string
     */
    public function getCode(): string
    {
        return 'new_behavior';
    }
}
