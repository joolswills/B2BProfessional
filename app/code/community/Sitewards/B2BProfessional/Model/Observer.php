<?php
/**
 * Sitewards_B2BProfessional_Model_Observer
 * 	- Observer to catch the following actions
 *
 * @category    Sitewards
 * @package     Sitewards_B2BProfessional
 * @copyright   Copyright (c) 2013 Sitewards GmbH (http://www.sitewards.com/)
 */
class Sitewards_B2BProfessional_Model_Observer {
	/**
	 * The last product Id
	 *
	 * @var int
	 */
	protected static $_iLastProductId = 0;

	/**
	 * Check if the site requires login to work
	 * 	- Add notice,
	 * 	- Redirect to the home page,
	 *
	 * @param Varien_Event_Observer $oObserver
	 */
	public function onControllerActionPreDispatch(Varien_Event_Observer $oObserver) {
		/* @var $oHelper Sitewards_B2BProfessional_Helper_Data */
		$oHelper = Mage::helper('b2bprofessional');
		if($oHelper->checkGlobalActive() == true) {
			/* @var $oControllerAction Mage_Core_Controller_Front_Action */
			$oControllerAction = $oObserver->getData('controller_action');

			/*
			 * Check to see if the system requires a login
			 * And there is no logged in user
			 */
			if($oHelper->checkRequireLogin() == true && !Mage::getSingleton('customer/session')->isLoggedIn()) {
				/*
				 * Check to see if the controller is:
				 * 	1) Cms related for cms pages,
				 * 	2) A front action to allow for admin pages,
				 * 	3) Customer account to allow for login
				 */
				if(
					!$oControllerAction instanceof Mage_Cms_IndexController
						&&
					!$oControllerAction instanceof Mage_Cms_PageController
						&&
					$oControllerAction instanceof Mage_Core_Controller_Front_Action
						&&
					!$oControllerAction instanceof Mage_Customer_AccountController
				){
					// Redirect to the homepage
					/* @var $oResponse Mage_Core_Controller_Response_Http */
					$oResponse = $oControllerAction->getResponse();
					$oResponse->setRedirect($oHelper->getRequireLoginRedirect());

					/*
					 * Add message to the session
					 * 	- Note:
					 * 		We need session_write_close otherwise the messages get lots in redirect
					 */
					/* @var $oSession Mage_Core_Model_Session */
					$oSession = Mage::getSingleton('core/session');
					$oSession->addNotice($oHelper->getRequireLoginMessage());
					session_write_close();
				}
			/*
			 * On Multishipping or Onepage actions
			 *  - validate that the cart is valid
			 *  - if not redirect the user to the account section and display message
			 */
			} elseif(
				$oControllerAction instanceof Mage_Checkout_MultishippingController
				||
				$oControllerAction instanceof Mage_Checkout_OnepageController
			) {
				if (!$oHelper->hasValidCart()) {
					// Stop the default action from being dispatched
					$oControllerAction->setFlag('', 'no-dispatch', true);
					//Set the appropriate error message to the user session
					Mage::getSingleton('customer/session')->addError($oHelper->getCheckoutMessage());
					//Redirect to the account login url
					Mage::app()->getResponse()->setRedirect(Mage::getUrl('customer/account/login'))->sendHeaders();
				}
			/*
			 * On Cart action
			 *  - validate that the cart is valid
			 *  - add message to the checkout session
			 */
			} elseif($oControllerAction instanceof Mage_Checkout_CartController) {
				if (!$oHelper->hasValidCart()) {
					Mage::getSingleton('checkout/session')->addError($oHelper->getCheckoutMessage());
				}
			}
		}
	}

	/**
	 * Check for block Mage_Catalog_Block_Product_Price
	 * 	- Check the product is active via the Sitewards_B2BProfessional_Helper_Data
	 * 	- Replace the text with that on the b2bprofessional
	 *
	 * @param Varien_Event_Observer $oObserver
	 * @return string
	 */
	public function onCoreBlockAbstractToHtmlAfter(Varien_Event_Observer $oObserver) {
		$oBlock = $oObserver->getData('block');
		$oTransport = $oObserver->getData('transport');

		/* @var $oB2BHelper Sitewards_B2BProfessional_Helper_Data */
		$oB2BHelper = Mage::helper('b2bprofessional');
		if(
			(
				$oBlock instanceof Mage_Catalog_Block_Product_Price
				||
				$oBlock instanceof Mage_Bundle_Block_Catalog_Product_Price
			)
			&&
			!$oBlock instanceof Mage_Bundle_Block_Catalog_Product_View_Type_Bundle_Option
		) {
			/* @var $oProduct Mage_Catalog_Model_Product */
			$oProduct = $oBlock->getProduct();

			$iCurrentProductId = $oProduct->getId();

			if ($oB2BHelper->checkActive($iCurrentProductId)) {
				// To stop duplicate information being displayed validate that we only do this once per product
				if ($iCurrentProductId != self::$_iLastProductId) {
					self::$_iLastProductId = $iCurrentProductId;

					$oTransport->setHtml($oB2BHelper->getPriceMessage());
				} else {
					$oTransport->setHtml('');
				}
			}
		} elseif(
			$oBlock instanceof Mage_Checkout_Block_Cart_Totals
			||
			$oBlock instanceof Mage_Checkout_Block_Onepage_Link
			||
			$oBlock instanceof Mage_Checkout_Block_Multishipping_Link
		) {
			/*
			 * If the current cart is not valid
			 *  - remove the block html
			 */
			if (!$oB2BHelper->hasValidCart()) {
				$oTransport->setHtml('');
			}
		} elseif (
			$oBlock instanceof Mage_Checkout_Block_Cart_Sidebar
		) {
			$aSections = array(
				'cart_sidebar_totals',
				'cart_sidebar_actions'
			);
			$sOriginalHtml = $oB2BHelper->replaceSections($aSections, $oTransport->getHtml());
			$oTransport->setHtml($sOriginalHtml);
		} elseif (
			$oBlock instanceof Mage_Checkout_Block_Cart_Item_Renderer
		) {
			$iProductId = $oBlock->getItem()->getProductId();
			$aSections = array(
				'cart_item_price'
			);
			$sOriginalHtml = $oB2BHelper->replaceSections($aSections, $oTransport->getHtml(), $iProductId);
			$oTransport->setHtml($sOriginalHtml);
		}
	}

	/**
	 * On the event core_block_abstract_to_html_before
	 * 	 - Check for the block type Mage_Catalog_Block_Product_List_Toolbar
	 * 	 - Remove the price order when required
	 *
	 * @param Varien_Event_Observer $oObserver
	 */
	public function onCoreBlockAbstractToHtmlBefore(Varien_Event_Observer $oObserver) {
		$oBlock = $oObserver->getData('block');

		if($oBlock instanceof Mage_Catalog_Block_Product_List_Toolbar) {
			/* @var $oB2BHelper Sitewards_B2BProfessional_Helper_Data */
			$oB2BHelper = Mage::helper('b2bprofessional');

			if($oB2BHelper->checkActive()) {
				$oBlock->removeOrderFromAvailableOrders('price');
			}
		}
	}

	/**
	 * On the event catalog_product_type_configurable_price
	 * Set the Configurable price of a product to 0 to stop the changed price showing up in the drop down
	 *
	 * @param Varien_Event_Observer $oObserver
	 */
	public function onCatalogProductTypeConfigurablePrice(Varien_Event_Observer $oObserver) {
		$oProduct = $oObserver->getData('product');
		/* @var $oB2BHelper Sitewards_B2BProfessional_Helper_Data */
		$oB2BHelper = Mage::helper('b2bprofessional');

		if ($oB2BHelper->checkActive($oProduct->getId())) {
			$oProduct->setConfigurablePrice(0);
		}
	}

	/**
	 * If we have a Mage_Catalog_Block_Layer_View
	 *	 - remove the price attribute
	 *
	 * @param Varien_Event_Observer $oObserver
	 */
	public function onCoreLayoutBlockCreateAfter(Varien_Event_Observer $oObserver) {
		$oBlock = $oObserver->getData('block');
		if($oBlock instanceof Mage_Catalog_Block_Layer_View) {
			/* @var $oB2BHelper Sitewards_B2BProfessional_Helper_Data */
			$oB2BHelper = Mage::helper('b2bprofessional');

			/*
			 * Get all possible category filters
			 * Assign to value b2bprof_category_filters to be used in
			 * Sitewards_B2BProfessional_Helper_Data->checkCategoryIsActive
			 */
			/* @var $oCategoryFilter Mage_Catalog_Block_Layer_Filter_Category */
			$oCategoryFilter = $oBlock->getChild('category_filter');
			$oCategories = $oCategoryFilter->getItems();
			$aCategoryOptions = array();
			foreach($oCategories as $oCategory) {
				/* @var $oCategory Mage_Catalog_Model_Layer_Filter_Item */
				$iCategoryId = $oCategory->getValue();
				$aCategoryOptions[] = $iCategoryId;
			}
			Mage::register('b2bprof_category_filters', $aCategoryOptions);

			if($oB2BHelper->checkActive()) {
				$aFilterableAttributes = $oBlock->getData('_filterable_attributes');
				$aNewFilterableAttributes = array();
				foreach ($aFilterableAttributes as $oFilterableAttribute) {
					if($oFilterableAttribute->getAttributeCode() != 'price') {
						$aNewFilterableAttributes[] = $oFilterableAttribute;
					}
				}
				$oBlock->setData('_filterable_attributes', $aNewFilterableAttributes);
			}
		}
	}

	/**
	 * On catalog_product_collection_load_after
	 *  - loop through each product and check if it is valid
	 *  - when no setCanShowPrice false
	 *
	 * @param Varien_Event_Observer $oObserver
	 */
	public function onCatalogProductCollectionLoadAfter(Varien_Event_Observer $oObserver) {
		$sControllerModule = Mage::app()->getRequest()->getControllerModule();
		$oProductCollection = $oObserver->getData('collection');

		if($sControllerModule == 'Mage_Catalog') {
			/* @var $oB2BHelper Sitewards_B2BProfessional_Helper_Data */
			$oB2BHelper = Mage::helper('b2bprofessional');
			foreach ($oProductCollection as $oProduct) {
				if ($oB2BHelper->checkActive($oProduct->getId())) {
					$oProduct->setCanShowPrice(false);
				}
			}
		}
	}

	/**
	 * On catalog_controller_product_view
	 *  - check if it is valid and not a bundle product
	 *  - when no setCanShowPrice false
	 *
	 * @param Varien_Event_Observer $oObserver
	 */
	public function onCatalogControllerProductView(Varien_Event_Observer $oObserver) {
		/* @var $oProduct Mage_Catalog_Model_Product */
		$oProduct = $oObserver->getData('product');

		/* @var $oB2BHelper Sitewards_B2BProfessional_Helper_Data */
		$oB2BHelper = Mage::helper('b2bprofessional');
		if ($oB2BHelper->checkActive($oProduct->getId()) && $oProduct->getTypeId() != 'bundle') {
			$oProduct->setCanShowPrice(false);
		}
	}
}