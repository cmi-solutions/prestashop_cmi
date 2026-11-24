<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Admin;

use Cmi\PrestaShop\Payment\PaymentState;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Definition\GridDefinitionInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Adds a "CMI" column to the back-office orders list: the OrderId known by CMI and the
 * payment's state.
 *
 * No filter of its own: PrestaShop 9.2 selects the page of orders in an inner query modules
 * cannot change, so a module filter would only narrow the rows already on the page. The
 * OrderId is the prefix + the order reference, so the list's Reference filter finds it.
 */
class OrderGrid
{
    public const COLUMN = 'cmi_payment';

    public static function modifyDefinition(GridDefinitionInterface $definition, \Module $module): void
    {
        $column = (new DataColumn(self::COLUMN))
            ->setName($module->getTranslator()->trans('CMI', [], 'Modules.Cmi.Admin'))
            ->setOptions(['field' => self::COLUMN, 'sortable' => false]);
        $columns = $definition->getColumns();
        try {
            $columns->addAfter('payment', $column);
        } catch (\Exception $e) {
            $columns->add($column);
        }
    }

    /**
     * @param \Doctrine\DBAL\Query\QueryBuilder $search
     */
    public static function modifyQuery($search, \Module $module): void
    {
        $join = 'cmt.`id_cart` = o.`id_cart` AND cmt.`id_shop` = o.`id_shop`';
        $case = 'CASE cmt.`payment_state`';
        foreach (PaymentState::ALL as $state) {
            $case .= " WHEN '" . pSQL($state) . "' THEN '" . pSQL(OrderPanel::stateLabel($state, $module)) . "'";
        }
        $case .= " ELSE '' END";
        $search->leftJoin('o', _DB_PREFIX_ . 'cmi_transaction', 'cmt', $join)
            ->addSelect("IF(cmt.`oid` IS NULL, '', CONCAT(cmt.`oid`, ' - ', " . $case . ')) AS `' . self::COLUMN . '`');
    }
}
