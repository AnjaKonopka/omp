<?php

/**
 * @file controllers/grid/settings/category/CategoryCategoryGridHandler.inc.php
 *
 * Copyright (c) 2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class CategoryCategoryGridHandler
 * @ingroup controllers_grid_settings_category
 *
 * @brief Handle operations for category management operations.
 */

// Import the base GridHandler.
import('lib.pkp.classes.controllers.grid.CategoryGridHandler');
import('lib.pkp.classes.controllers.grid.DataObjectGridCellProvider');

// Import user group grid specific classes
import('controllers.grid.settings.category.CategoryGridCategoryRow');

// Link action & modal classes
import('lib.pkp.classes.linkAction.request.AjaxModal');

class CategoryCategoryGridHandler extends CategoryGridHandler {
	var $_pressId;

	/**
	 * Constructor
	 */
	function CategoryCategoryGridHandler() {
		parent::CategoryGridHandler();
		$this->addRoleAssignment(
			array(ROLE_ID_MANAGER),
			array(
				'fetchGrid',
				'fetchCategory',
				'fetchRow',
				'addCategory',
				'editCategory',
				'updateCategory',
				'deleteCategory',
				'uploadImage'
			)
		);
	}

	//
	// Overridden methods from PKPHandler.
	//
	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
		import('lib.pkp.classes.security.authorization.PkpContextAccessPolicy');
		$this->addPolicy(new PkpContextAccessPolicy($request, $roleAssignments));
		return parent::authorize($request, $args, $roleAssignments);
	}


	/**
	 * @copydoc PKPHandler::initialize()
	 */
	function initialize($request) {

		parent::initialize($request);

		$press = $request->getPress();
		$this->_pressId = $press->getId();

		AppLocale::requireComponents(LOCALE_COMPONENT_APP_MANAGER);

		// Set the grid title.
		$this->setTitle('grid.category.categories');

		$this->setInstructions('manager.setup.categories.description');

		// Add grid-level actions.
		$router = $request->getRouter();
		$this->addAction(
			new LinkAction(
				'addCategory',
				new AjaxModal(
					$router->url($request, null, null, 'addCategory'),
					__('grid.category.add'),
					'modal_manage'
				),
				__('grid.category.add'),
				'add_category'
			)
		);

		// Add grid columns.
		$cellProvider = new DataObjectGridCellProvider();
		$cellProvider->setLocale(AppLocale::getLocale());

		$this->addColumn(
			new GridColumn(
				'title',
				'grid.category.name',
				null,
				null,
				$cellProvider
			)
		);
	}

	/**
	 * @copydoc GridHandler::loadData
	 */
	function loadData($request, $filter) {
		// For top-level rows, only list categories without parents.
		$categoryDao = DAORegistry::getDAO('CategoryDAO');
		$categoriesIterator = $categoryDao->getByParentId(null, $this->_getPressId());
		return $categoriesIterator->toAssociativeArray();
	}

	/**
	 * @copydoc CategoryGridHandler::getCategoryRowIdParameterName()
	 */
	function getCategoryRowIdParameterName() {
		return 'parentCategoryId';
	}

	/**
	 * @copydoc GridHandler::getRowInstance()
	 */
	function getRowInstance() {
		import('controllers.grid.settings.category.CategoryGridRow');
		return new CategoryGridRow();
	}

	/**
	 * @copydoc CategoryGridHandler::geCategorytRowInstance()
	 */
	function getCategoryRowInstance() {
		return new CategoryGridCategoryRow();
	}

	/**
	 * @copydoc CategoryGridHandler::loadCategoryData()
	 */
	function loadCategoryData($request, &$category, $filter) {
		$categoryId = $category->getId();
		$categoryDao = DAORegistry::getDAO('CategoryDAO');
		$categoriesIterator = $categoryDao->getByParentId($categoryId, $this->_getPressId());
		$categories = $categoriesIterator->toAssociativeArray();
		return $categories;
	}

	/**
	 * Handle the add category operation.
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function addCategory($args, $request) {
		return $this->editCategory($args, $request);
	}

	/**
	 * Handle the edit category operation.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage JSON object
	 */
	function editCategory($args, $request) {
		$categoryForm = $this->_getCategoryForm($request);

		$categoryForm->initData();

		return new JSONMessage(true, $categoryForm->fetch($request));
	}

	/**
	 * Update category data in database and grid.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage JSON object
	 */
	function updateCategory($args, $request) {
		$categoryForm = $this->_getCategoryForm($request);

		$categoryForm->readInputData();
		if($categoryForm->validate()) {
			$categoryForm->execute($request);
			return DAO::getDataChangedEvent();
		} else {
			return new JSONMessage(true, $categoryForm->fetch($request));
		}
	}

	/**
	 * Delete a category
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage JSON object
	 */
	function deleteCategory($args, $request) {
		// Identify the category to be deleted
		$categoryDao = DAORegistry::getDAO('CategoryDAO');
		$press = $request->getPress();
		$category = $categoryDao->getById(
			$request->getUserVar('categoryId'),
			$press->getId()
		);

		// FIXME delete dependent objects?

		// Delete the category
		$categoryDao->deleteObject($category);
		return DAO::getDataChangedEvent($category->getId(), $category->getParentId());
	}

	/**
	 * Handle file uploads for cover/image art for things like Series and Categories.
	 * @param $request PKPRequest
	 * @param $args array
	 * @return JSONMessage JSON object
	 */
	function uploadImage($args, $request) {
		$user = $request->getUser();

		import('lib.pkp.classes.file.TemporaryFileManager');
		$temporaryFileManager = new TemporaryFileManager();
		$temporaryFile = $temporaryFileManager->handleUpload('uploadedFile', $user->getId());
		if ($temporaryFile) {
			$json = new JSONMessage(true);
			$json->setAdditionalAttributes(array(
					'temporaryFileId' => $temporaryFile->getId()
			));
			return $json;
		} else {
			return new JSONMessage(false, __('common.uploadFailed'));
		}
	}

	//
	// Private helper methods.
	//
	/**
	 * Get a CategoryForm instance.
	 * @param $request Request
	 * @return UserGroupForm
	 */
	function _getCategoryForm($request) {
		// Get the category ID.
		$categoryId = (int) $request->getUserVar('categoryId');

		// Instantiate the files form.
		import('controllers.grid.settings.category.form.CategoryForm');
		$pressId = $this->_getPressId();
		return new CategoryForm($pressId, $categoryId);
	}

	/**
	 * Get press id.
	 * @return int
	 */
	function _getPressId() {
		return $this->_pressId;
	}
}

?>
