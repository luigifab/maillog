<?php
/**
 * Created M/10/11/2015
 * Updated D/31/05/2020
 *
 * Copyright 2015-2020 | Fabrice Creuzot (luigifab) <code~luigifab~fr>
 * Copyright 2015-2016 | Fabrice Creuzot <fabrice.creuzot~label-park~com>
 * Copyright 2017-2018 | Fabrice Creuzot <fabrice~reactive-web~fr>
 * https://www.luigifab.fr/openmage/maillog
 *
 * This program is free software, you can redistribute it or modify
 * it under the terms of the GNU General Public License (GPL) as published
 * by the free software foundation, either version 2 of the license, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but without any warranty, without even the implied warranty of
 * merchantability or fitness for a particular purpose. See the
 * GNU General Public License (GPL) for more details.
 */

class Luigifab_Maillog_Model_Sync extends Mage_Core_Model_Abstract {

	protected $_eventPrefix = 'maillog_sync';

	public function _construct() {
		$this->_init('maillog/sync');
	}


	// action
	public function updateNow($send = true) {

		$now = time();
		if (empty($this->getId()))
			Mage::throwException('You must load a sync before trying to sync it.');

		// 0 action : 1 type : 2 id : 3 ancien-email : 4 email
		// 0 action : 1 type : 2 id : 3              : 4 email
		$info = (array) explode(':', $this->getData('action')); // (yes)

		try {
			$this->setData('status', 'running');
			$this->save();

			// chargement des objets du client
			if ($info[1] == 'customer') {

				$customer   = Mage::getModel('customer/customer')->load($info[2]);
				$billing    = $customer->getDefaultBillingAddress();
				$shipping   = $customer->getDefaultShippingAddress();
				$subscriber = Mage::getModel('newsletter/subscriber')->loadByEmail($customer->getData('email'), $customer->getStoreId());
				$object     = $this->initSpecialObject($customer);

				if (!empty($info[3])) {
					$customer->setOrigData('email', $info[3]);
					$customer->setData('email', $info[4]);
				}

				if (is_object($billing) && empty($billing->getData('is_default_billing')))
					$billing->setData('is_default_billing', 1);
				if (is_object($shipping) && empty($shipping->getData('is_default_shipping')))
					$shipping->setData('is_default_shipping', 1);
			}
			else if ($info[1] == 'subscriber') {

				$subscriber = Mage::getModel('newsletter/subscriber')->load($info[2]);

				$customer = Mage::getModel('customer/customer');
				$customer->setOrigData('email', $subscriber->getOrigData('subscriber_email'));
				$customer->setData('email', $subscriber->getData('subscriber_email'));
				$customer->setData('store_id', $subscriber->getStoreId());

				$billing  = null;
				$shipping = null;
				$object   = $this->initSpecialObject($customer);
			}
			else {
				Mage::throwException('Unknown sync type.');
			}

			// action
			// note très très importante, le + fait en sorte que ce qui est déjà présent n'est pas écrasé
			// par exemple, si entity_id est trouvé dans $customer, même si entity_id est trouvé dans $billing,
			// c'est bien l'entity_id de customer qui est utilisé
			$allow  = Mage::getStoreConfigFlag('maillog_sync/general/send') ? Mage::helper('maillog')->canSend(...$info) : false;
			$system = Mage::helper('maillog')->getSystem();
			$data   = $system->mapFields($customer);
			$data  += $system->mapFields($billing);
			$data  += $system->mapFields($shipping);
			$data  += $system->mapFields($subscriber);
			$data  += $system->mapFields($object);

			if ($allow !== true) {
				$this->setData('duration', time() - $now);
				$this->saveAllData($data, null, false);
				$this->setData('status', 'notsync');
				$this->setData('response', $allow);
				$this->save();
			}
			else if ($send) {
				$result = $system->updateCustomer($data);
				$this->setData('duration', time() - $now);
				$this->saveAllData($data, $result);
			}
			else {
				$this->saveAllData($data, null);
			}
		}
		catch (Exception $e) {
			Mage::logException($e);
		}

		return empty($data) ? null : $data;
	}

	public function deleteNow() {

		$now = time();
		if (empty($this->getId()))
			Mage::throwException('You must load a sync before trying to sync it.');

		// 0 action : 1 type : 2 id : 3 ancien-email : 4 email
		// 0 action : 1 type : 2 id : 3              : 4 email
		$info = (array) explode(':', $this->getData('action')); // (yes)

		try {
			$this->setData('status', 'running');
			$this->save();

			// simulation du client
			$customer = Mage::getModel('customer/customer');
			$customer->setOrigData('email', $info[4]);
			$customer->setData('email', $info[4]);

			$allow  = Mage::getStoreConfigFlag('maillog_sync/general/send') ? Mage::helper('maillog')->canSend(...$info) : false;
			$system = Mage::helper('maillog')->getSystem();
			$data   = $system->mapFields($customer);

			if ($allow !== true) {
				$this->setData('duration', time() - $now);
				$this->saveAllData($data, null, false);
				$this->setData('status', 'notsync');
				$this->setData('response', $allow);
				$this->save();
			}
			else {
				$result = $system->deleteCustomer($data);
				$this->setData('duration', time() - $now);
				$this->saveAllData($data, $result);
			}
		}
		catch (Exception $e) {
			Mage::logException($e);
		}

		return empty($data) ? null : $data;
	}


	// gestion des données des objets et de l'historique
	// si le saveAllData est fait dans une transaction, s'il y a un rollback, tout est perdu
	// dans ce cas ne pas oublier de refaire un saveAllData
	private function initSpecialObject($customer) {

		$object = new Varien_Object();
		$object->setData('last_sync_date', date('Y-m-d H:i:s'));

		if (!empty($id = $customer->getId())) {

			$database = Mage::getSingleton('core/resource');
			$read = $database->getConnection('core_read');

			// customer_group_code (lecture express depuis la base de données)
			$name = $read->fetchOne($read->select()
				->from($database->getTableName('customer_group'), 'customer_group_code')
				->where('customer_group_id = ?', $customer->getGroupId())
				->limit(1));

			$object->setData('group_name', $name);

			// login_at (lecture express depuis la base de données)
			// si non disponible, utilise la date d'inscription du client
			$last = $read->fetchOne($read->select()
				->from($database->getTableName('log_customer'), 'login_at')
				->where('customer_id = ?', $id)
				->order('log_id desc')
				->limit(1));

			$object->setData('last_login_date', (mb_strlen($last) > 10) ? $last : $customer->getData('created_at'));

			// commandes
			$orders = Mage::getResourceModel('sales/order_collection')
				->addFieldToFilter('customer_id', $id)
				->addFieldToFilter('state', ['in' => ['processing', 'complete', 'closed']])
				->setOrder('created_at', 'desc');

			if (!empty($numberOfOrders = $orders->getSize())) {

				$last = $orders->getLastItem();
				$object->setData('first_order_date',        $last->getData('created_at'));
				$object->setData('first_order_total',       (float) $last->getData('base_grand_total'));
				$object->setData('first_order_total_notax', (float) $last->getData('base_grand_total') - $last->getData('base_tax_amount'));

				$first = $orders->getFirstItem();
				$object->setData('last_order_date',        $first->getData('created_at'));
				$object->setData('last_order_total',       (float) $first->getData('base_grand_total'));
				$object->setData('last_order_total_notax', (float) $first->getData('base_grand_total') - $first->getData('base_tax_amount'));

				$orders->clear();
				$orders->getSelect()->columns([
					'sumincltax' => 'SUM(main_table.base_grand_total)',
					'sumexcltax' => 'SUM(main_table.base_grand_total) - SUM(main_table.base_tax_amount)'
				])->group('customer_id');

				$item = $orders->getFirstItem();
				$object->setData('average_order_amount',       (float) $item->getData('sumincltax') / $numberOfOrders);
				$object->setData('average_order_amount_notax', (float) $item->getData('sumexcltax') / $numberOfOrders);
				$object->setData('total_order_amount',         (float) $item->getData('sumincltax'));
				$object->setData('total_order_amount_notax',   (float) $item->getData('sumexcltax'));
				$object->setData('number_of_orders',           $numberOfOrders);
			}
		}

		return $object;
	}

	private function transformDataForHistory($data, $asString = true) {

		$inline = [];

		if (is_array($data)) {
			foreach ($data as $key => $value) {
				if (is_array($value)) {
					$subdata = $this->transformDataForHistory($value, false);
					foreach ($subdata as $subvalue)
						$inline[] = sprintf('[%s]%s', $key, $subvalue);
				}
				else {
					$inline[] = sprintf('[%s] %s%s', $key, $value, "\n");
				}
			}
		}
		else {
			$inline[] = empty($data) ? '(no result)' : $data;
		}

		return $asString ? trim(implode($inline)) : $inline;
	}

	public function saveAllData($request, $response, $save = true) {

		if (!empty($request) && is_array($request)) {

			ksort($request);
			$mapping = array_filter(preg_split('#\s+#', Mage::getStoreConfig('maillog_sync/general/mapping_config')));
			$lines   = explode("\n", $this->transformDataForHistory($request));

			foreach ($mapping as $map) {
				$map = explode(':', $map);
				$tmp = trim(array_shift($map));
				foreach ($lines as &$line) {
					// emarsys  [2] Test
					if (mb_stripos($line, '['.$tmp.']') !== false) {
						$line .= ((mb_substr($line, -2) == '] ') ? ' --' : '').' ('.implode(' ', $map).')';
						break;
					}
					// dolist   [Fields][x][Name] lastname
					if (mb_stripos($line, '][Name] '.$tmp) !== false) {
						$line .= ' ('.implode(' ', $map).')';
						break;
					}
				}
				unset($line);
			}

			$this->setData('request', implode("\n", $lines));
		}

		$system   = Mage::helper('maillog')->getSystem();
		$status   = $system->checkResponse($response) ? 'success' : 'error';
		$response = $system->extractResponseData($response, true);

		$this->setData('response', $this->transformDataForHistory($response));
		$this->setData('sync_at', date('Y-m-d H:i:s'));
		$this->setData('status', $status);

		if ($save)
			$this->save();
	}
}