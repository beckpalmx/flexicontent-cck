<?php
/**
 * @version 1.5 stable $Id$
 * @package Joomla
 * @subpackage FLEXIcontent
 * @copyright (C) 2009 Emmanuel Danan - www.vistamedia.fr
 * @license GNU/GPL v2
 * 
 * FLEXIcontent is a derivative work of the excellent QuickFAQ component
 * @copyright (C) 2008 Christoph Lukes
 * see www.schlu.net for more information
 *
 * FLEXIcontent is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

defined( '_JEXEC' ) or die( 'Restricted access' );

jimport('joomla.application.component.controller');

/**
 * FLEXIcontent Component Item Controller
 *
 * @package Joomla
 * @subpackage FLEXIcontent
 * @since 1.0
 */
class FlexicontentControllerItems extends FlexicontentController
{
	/**
	 * Constructor
	 *
	 * @since 1.0
	 */
	function __construct()
	{
		parent::__construct();

		// Register Extra task
		$this->registerTask( 'add',        'edit' );
		$this->registerTask( 'apply_type', 'save' );
		$this->registerTask( 'apply',      'save' );
		$this->registerTask( 'saveandnew', 'save' );
	}
	
	
	/**
	 * Logic to save an item
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function save()
	{
		// Check for request forgeries
		JRequest::checkToken() or jexit( 'Invalid Token' );
		//echo '<html>  <meta http-equiv="content-type" content="text/html; charset=utf-8" /> <body>';
		
		// Initialize variables
		$app     = JFactory::getApplication();
		$db      = JFactory::getDBO();
		$user    = JFactory::getUser();
		$config  = JFactory::getConfig();
		$session = JFactory::getSession();
		$task	   = JRequest::getVar('task');
		$model   = $this->getModel('item');
		$isnew   = !$model->getId();
		$ctrl_task = FLEXI_J16GE ? 'task=items.' : 'controller=items&task=';
		
		// Get component parameters
		$params = clone( JComponentHelper::getParams('com_flexicontent') );
		
		// Merge the type parameters
		$tparams = $model->getTypeparams();
		$tparams = FLEXI_J16GE ? new JRegistry($tparams) : new JParameter($tparams);
		$params->merge($tparams);
		
		
		// *********************
		// Get data from request
		// *********************
		
		// Retrieve form data these are subject to basic filtering
		$data   = JRequest::getVar('jform', array(), 'post', 'array');   // Core Fields and and item Parameters
		$custom = JRequest::getVar('custom', array(), 'post', 'array');  // Custom Fields
		$jfdata = JRequest::getVar('jfdata', array(), 'post', 'array');  // Joomfish Data
		$unique_tmp_itemid = JRequest::getVar( 'unique_tmp_itemid' );
		
		// Set data id into model in case not already set ?
		$model->setId((int) $data['id']);
		
		
		
		// *************************************
		// ENFORCE can change category ACL perms
		// *************************************
		
		$perms = FlexicontentHelperPerm::getPerm();
		// Per content type change category permissions
		// Per content type change category permissions
		$current_type_id  = ($isnew || !$model->get('type_id')) ? $data['type_id'] : $model->get('type_id');  // GET current (existing/old) item TYPE ID
		$CanChangeFeatCat = $user->authorise('flexicontent.change.cat.feat', 'com_flexicontent.type.' . $current_type_id);
		$CanChangeSecCat  = $user->authorise('flexicontent.change.cat.sec', 'com_flexicontent.type.' . $current_type_id);
		$CanChangeCat     = $user->authorise('flexicontent.change.cat', 'com_flexicontent.type.' . $current_type_id);
		
		$AutoApproveChanges = $perms->AutoApproveChanges;
		
		$featured_cats_parent = $params->get('featured_cats_parent', 0);
		$featured_cats = array();
		
		$enable_featured_cid_selector = $perms->MultiCat && $CanChangeFeatCat;
		$enable_cid_selector   = $perms->MultiCat && $CanChangeSecCat;
		$enable_catid_selector = ($isnew && !$tparams->get('catid_default')) || (!$isnew && !$model->get('catid')) || $CanChangeCat;
		
		$featured_cats_parent = $params->get('featured_cats_parent', 0);
		$featured_cats = array();
		if ( $featured_cats_parent && !$enable_featured_cid_selector )
		{
			$featured_tree = flexicontent_cats::getCategoriesTree($published_only=1, $parent_id=$featured_cats_parent, $depth_limit=0);
			$disabled_cats = $params->get('featured_cats_parent_disable', 1) ? array($featured_cats_parent) : array();
			
			$featured_cid = array();
			foreach($model->get('cats') as $item_cat) {
				if (isset($featured_tree[$item_cat]) && !isset($disabled_cats[$item_cat])) $featured_cid[] = $item_cat;
			}
			$data['featured_cid'] = $featured_cid;
		}
		
		if (!$enable_cid_selector) {
			if ($isnew) {
				$data['cid'] = $tparams->get('cid_default');
			} else if ( isset($featured_cid) ) {
				$featured_cid_arr = array_flip($featured_cid);
				$sec_cid = array();
				foreach($model->get('cats') as $item_cat) if (!isset($featured_cid_arr[$item_cat])) $sec_cid[] = $item_cat;
				$data['cid'] = $sec_cid;
			} else {
				$data['cid'] = $model->get('cats');
			}
		}
		
		if (!$enable_catid_selector) {
			if ($isnew)
				$data['catid'] = $tparams->get('catid_default');
			else if ($tparams->get('catid_default'))
				$data['catid'] = $model->get('catid');
		}
		
		
		
		// **************************
		// Basic Form data validation
		// **************************
		
		// Validate Form data for core fields and for parameters
		$form = $model->getForm();          // Do not pass any data we only want the form object in order to validate the data and not create a filled-in form
		$post = $model->validate($form, $data);
		
		// Check for validation error
		if (!$post) {
			// Get the validation messages and push up to three validation messages out to the user
			$errors	= $form->getErrors();
			for ($i = 0, $n = count($errors); $i < $n && $i < 3; $i++) {
				$app->enqueueMessage($errors[$i] instanceof Exception ? $errors[$i]->getMessage() : $errors[$i], 'error');
			}
			
			// Set POST form date into the session, so that they get reloaded
			$app->setUserState($form->option.'.edit.'.$form->context.'.data', $data);      // Save the jform data in the session
			$app->setUserState($form->option.'.edit.'.$form->context.'.custom', $custom);  // Save the custom fields data in the session
			$app->setUserState($form->option.'.edit.'.$form->context.'.jfdata', $jfdata);  // Save the falang translations into the session
			$app->setUserState($form->option.'.edit.'.$form->context.'.unique_tmp_itemid', $unique_tmp_itemid);  // Save temporary unique item id into the session
			
			// Redirect back to the registration form.
			$this->setRedirect( $_SERVER['HTTP_REFERER'] );
			return false; //die('error');
		}
		
		// Some values need to be assigned after validation
		$post['attribs'] = @$data['attribs'];  // Workaround for item's template parameters being clear by validation since they are not present in item.xml
		$post['custom']  = & $custom;          // Assign array of custom field values, they are in the 'custom' form array instead of jform
		$post['jfdata']  = & $jfdata;          // Assign array of Joomfish field values, they are in the 'jfdata' form array instead of jform
		
		// Assign template parameters of the select ilayout as an sub-array (the DB model will handle the merging of parameters)
		$ilayout = $data['attribs']['ilayout'];
		if( !empty($data['layouts'][$ilayout]) ) {
			//echo "<pre>"; print_r($post['attribs']);
			//$post['attribs'] = array_merge($post['attribs'], $data['layouts'][$ilayout]);
			$post['attribs']['layouts'] = $data['layouts'];
			//echo "<pre>"; print_r($post['attribs']); exit;
		}
		
		// USEFULL FOR DEBUGING for J2.5 (do not remove commented code)
		//$diff_arr = array_diff_assoc ( $data, $post);
		//echo "<pre>"; print_r($diff_arr); exit();
		
		
		// Make sure Content ID in the REQUEST is set, this is needed in BACKEND, needed in some cases
		// NOTE this is not the same as jform['cid'] which is the category IDs of the Content Item
		JRequest::setVar( 'cid', array($model->getId()), 'post', 'array' );
		
		
		// ********************************************************************************
		// PERFORM ACCESS CHECKS, NOTE: we need to check access again, despite having
		// checked them on edit form load, because user may have tampered with the form ... 
		// ********************************************************************************
		
		$itemAccess = $model->getItemAccess();
		$canAdd  = $itemAccess->get('access-create');
		$canEdit = $itemAccess->get('access-edit');
		
		$type_id = (int) $post['type_id'];  // Typecast to int, (already done for J2.5 via validating)
		if ( !$isnew && $model->get('type_id') == $type_id) {
			// Existing item with Type not being ALTERED, content type can be maintained regardless of privilege
			$canCreateType = true;
		} else {
			// New item or existing item with Type is being ALTERED, check privilege to create items of this type
			$canCreateType = $model->canCreateType( array($type_id), true, $types );
		}
		
		if ( !$canCreateType ) {
			$msg = isset($types[$type_id]) ?
				JText::sprintf( 'FLEXI_NO_ACCESS_CREATE_CONTENT_OF_TYPE', JText::_($types[$type_id]->name) ) :
				' Content Type '.$type_id.' was not found OR is not published';
			JError::raiseWarning( 403, $msg );
			$this->setRedirect( 'index.php?option=com_flexicontent&view=items', '' );
			return;
		}
		
		if ( !$canEdit ) {
			// No edit privilege, check if item is editable till logoff
			if ($session->has('rendered_uneditable', 'flexicontent')) {
				$rendered_uneditable = $session->get('rendered_uneditable', array(),'flexicontent');
				$canEdit = isset($rendered_uneditable[$model->get('id')]) && $rendered_uneditable[$model->get('id')];
			}
		}
		
		
		// New item: check if user can create in at least one category
		if ($isnew && !$canAdd) {
			JError::raiseWarning( 403, JText::_( 'FLEXI_NO_ACCESS_CREATE' ) );
			$this->setRedirect( 'index.php?option=com_flexicontent&view=items', '' );
			return;
		}
		
		
		// Existing item: Check if user can edit current item
		if (!$isnew && !$canEdit) {
			JError::raiseWarning( 403, JText::_( 'FLEXI_NO_ACCESS_EDIT' ) );
			$this->setRedirect( 'index.php?option=com_flexicontent&view=items', '' );
			return;
		}
		
		// Get "BEFORE SAVE" categories for information mail
		$before_cats = array();
		if ( !$isnew )
		{
			$query 	= 'SELECT DISTINCT c.id, c.title FROM #__categories AS c'
				. ' LEFT JOIN #__flexicontent_cats_item_relations AS rel ON rel.catid = c.id'
				. ' WHERE rel.itemid = '.(int) $model->get('id');
			$db->setQuery( $query );
			$before_cats = $db->loadObjectList('id');
			$before_maincat = $model->get('catid');
			$original_item = $model->getItem($post['id'], $check_view_access=false, $no_cache=true, $force_version=0);
		}
		
		
		// ****************************************
		// Try to store the form data into the item
		// ****************************************
		if ( ! $model->store($post) )
		{
			// Set error message about saving failed, and also the reason (=model's error message)
			$msg = JText::_( 'FLEXI_ERROR_STORING_ITEM' );
			JError::raiseWarning( 500, $msg .": " . $model->getError() );

			// Since an error occured, check if (a) the item is new and (b) was not created
			if ($isnew && !$model->get('id')) {
				$msg = '';
				$link = 'index.php?option=com_flexicontent&'.$ctrl_task.'add&cid=0&typeid='.$type_id;
				$this->setRedirect($link, $msg);
			} else {
				$msg = '';
				$link = 'index.php?option=com_flexicontent&'.$ctrl_task.'edit&cid='.$model->get('id');
				$this->setRedirect($link, $msg);
			}
			
			// Saving has failed check-in and return, (above redirection will be used)
			$model->checkin();
			return;
		}
		
		
		// **************************************************
		// Check in model and get item id in case of new item
		// **************************************************
		$model->checkin();
		$post['id'] = $isnew ? (int) $model->get('id') : $post['id'];
		
		// Get items marked as newly submitted
		$newly_submitted = $session->get('newly_submitted', array(), 'flexicontent');
		if ($isnew) {
			// Mark item as newly submitted, to allow to a proper "THANKS" message after final save & close operation (since user may have clicked add instead of add & close)
			$newly_submitted[$model->get('id')] = 1;
			$session->set('newly_submitted', $newly_submitted, 'flexicontent');
		}
		$newly_submitted_item = @ $newly_submitted[$model->get('id')];
		
		
		// ***********************************************************************************************************
		// Get newly saved -latest- version (store task gets latest) of the item, and also calculate publish privelege
		// ***********************************************************************************************************
		$item = $model->getItem($post['id'], $check_view_access=false, $no_cache=true, $force_version=-1);
		$canPublish = $model->canEditState( $item, $check_cat_perm=true );
		
		
		// ********************************************************************************************
		// Use session to detect multiple item saves to avoid sending notification EMAIL multiple times
		// ********************************************************************************************
		$is_first_save = true;
		if ($session->has('saved_fcitems', 'flexicontent')) {
			$saved_fcitems = $session->get('saved_fcitems', array(), 'flexicontent');
			$is_first_save = $isnew ? true : !isset($saved_fcitems[$model->get('id')]);
		}
		// Add item to saved items of the corresponding session array
		$saved_fcitems[$model->get('id')] = $timestamp = time();  // Current time as seconds since Unix epoc;
		$session->set('saved_fcitems', $saved_fcitems, 'flexicontent');
		
		
		// ********************************************
		// Get categories added / removed from the item
		// ********************************************
		$query 	= 'SELECT DISTINCT c.id, c.title FROM #__categories AS c'
			. ' LEFT JOIN #__flexicontent_cats_item_relations AS rel ON rel.catid = c.id'
			. ' WHERE rel.itemid = '.(int) $model->get('id');
		$db->setQuery( $query );
		$after_cats = $db->loadObjectList('id');
		if ( !$isnew ) {
			$cats_added_ids = array_diff(array_keys($after_cats), array_keys($before_cats));
			foreach($cats_added_ids as $cats_added_id) {
				$cats_added_titles[] = $after_cats[$cats_added_id]->title;
			}
			
			$cats_removed_ids = array_diff(array_keys($before_cats), array_keys($after_cats));
			foreach($cats_removed_ids as $cats_removed_id) {
				$cats_removed_titles[] = $before_cats[$cats_removed_id]->title;
			}
			$cats_altered = count($cats_added_ids) + count($cats_removed_ids);
			$after_maincat = $model->get('catid');
		}
		
		
		// *******************************************************************************************************************
		// We need to get emails to notify, from Global/item's Content Type parameters -AND- from item's categories parameters
		// *******************************************************************************************************************
		$notify_emails = array();
		if ( $is_first_save || $cats_altered || $params->get('nf_enable_debug',0) )
		{
			// Get needed flags regarding the saved items
			$approve_version = 2;
			$pending_approval_state = -3;
			$draft_state = -4;
			
			$current_version = FLEXIUtilities::getCurrentVersions($item->id, true); // Get current item version
			$last_version    = FLEXIUtilities::getLastVersions($item->id, true);    // Get last version (=latest one saved, highest version id),
			
			// $post variables vstate & state may have been (a) tampered in the form, and/or (b) altered by save procedure so better not use them
			$needs_version_reviewal     = !$isnew && ($last_version > $current_version) && !$canPublish && !$AutoApproveChanges;
			$needs_publication_approval =  $isnew && ($item->state == $pending_approval_state) && !$canPublish;
			
			$draft_from_non_publisher = $item->state==$draft_state && !$canPublish;
			
			if ($draft_from_non_publisher) {
				// Suppress notifications for draft-state items (new or existing ones), for these each author will publication approval manually via a button
				$nConf = false;
			} else {
				// Get notifications configuration and select appropriate emails for current saving case
				$nConf = $model->getNotificationsConf($params);  //echo "<pre>"; print_r($nConf); "</pre>";
			}
			
			if ($nConf)
			{
				$states_notify_new = $params->get('states_notify_new', array(1,0,(FLEXI_J16GE ? 2:-1),-3,-4,-5));
				if ( empty($states_notify_new) )						$states_notify_new = array();
				else if ( ! is_array($states_notify_new) )	$states_notify_new = !FLEXI_J16GE ? array($states_notify_new) : explode("|", $states_notify_new);
				
				$states_notify_existing = $params->get('states_notify_existing', array(1,0,(FLEXI_J16GE ? 2:-1),-3,-4,-5));
				if ( empty($states_notify_existing) )						$states_notify_existing = array();
				else if ( ! is_array($states_notify_existing) )	$states_notify_existing = !FLEXI_J16GE ? array($states_notify_existing) : explode("|", $states_notify_existing);

				$n_state_ok = in_array($item->state, $states_notify_new);
				$e_state_ok = in_array($item->state, $states_notify_existing);
				
				if ($needs_publication_approval)   $notify_emails = $nConf->emails->notify_new_pending;
				else if ($isnew && $n_state_ok)    $notify_emails = $nConf->emails->notify_new;
				else if ($isnew)                   $notify_emails = array();
				else if ($needs_version_reviewal)  $notify_emails = $nConf->emails->notify_existing_reviewal;
				else if (!$isnew && $e_state_ok)   $notify_emails = $nConf->emails->notify_existing;
				else if (!$isnew)                  $notify_emails = array();
				
				if ($needs_publication_approval)   $notify_text = $params->get('text_notify_new_pending');
				else if ($isnew)                   $notify_text = $params->get('text_notify_new');
				else if ($needs_version_reviewal)  $notify_text = $params->get('text_notify_existing_reviewal');
				else if (!$isnew)                  $notify_text = $params->get('text_notify_existing');
				//print_r($notify_emails); exit;
			}
		}
		
		
		// *********************************************************************************************************************
		// If there are emails to notify for current saving case, then send the notifications emails, but 
		// *********************************************************************************************************************
		if ( !empty($notify_emails) && count($notify_emails) ) {
			$notify_vars = new stdClass();
			$notify_vars->needs_version_reviewal     = $needs_version_reviewal;
			$notify_vars->needs_publication_approval = $needs_publication_approval;
			$notify_vars->isnew         = $isnew;
			$notify_vars->notify_emails = $notify_emails;
			$notify_vars->notify_text   = $notify_text;
			$notify_vars->before_cats   = $before_cats;
			$notify_vars->after_cats    = $after_cats;
			$notify_vars->original_item = @ $original_item;
			
			$model->sendNotificationEmails($notify_vars, $params, $manual_approval_request=0);
		}
		
		
		// ***************************************************
		// CLEAN THE CACHE so that our changes appear realtime
		// ***************************************************
		$cache = FLEXIUtilities::getCache($group='', 0);
		$cache->clean('com_flexicontent_items');
		$cache->clean('com_flexicontent_filters');
		$cache = FLEXIUtilities::getCache($group='', 1);
		$cache->clean('com_flexicontent_items');
		$cache->clean('com_flexicontent_filters');
		
		
		// ****************************************************************************************************************************
		// Recalculate EDIT PRIVILEGE of new item. Reason for needing to do this is because we can have create permission in a category
		// and thus being able to set this category as item's main category, but then have no edit/editown permission for this category
		// ****************************************************************************************************************************
		$asset = 'com_content.article.' . $model->get('id');
		$canEdit = $user->authorise('core.edit', $asset) || ($user->authorise('core.edit.own', $asset) && $model->get('created_by') == $user->get('id'));
		// ALTERNATIVE 1
		//$canEdit = $model->getItemAccess()->get('access-edit'); // includes privileges edit and edit-own
		// ALTERNATIVE 2
		//$rights = FlexicontentHelperPerm::checkAllItemAccess($user->get('id'), 'item', $model->get('id'));
		//$canEdit = in_array('edit', $rights) || (in_array('edit.own', $rights) && $model->get('created_by') == $user->get('id')) ;
		
		
		// *******************************************************************************************************
		// Check if user can not edit item further (due to changed main category, without edit/editown permission)
		// *******************************************************************************************************
		if (!$canEdit)
		{
			if ($task=='apply' || $task=='apply_type') {
				// APPLY TASK: Temporarily set item to be editable till closing it
				$rendered_uneditable = $session->get('rendered_uneditable', array(),'flexicontent');
				$rendered_uneditable[$model->get('id')]  = 1;
				$session->set('rendered_uneditable', $rendered_uneditable, 'flexicontent');
				$canEdit = 1;
			}
			
			else if ( $newly_submitted_item ) {
				// NEW ITEM: Do not use editable till logoff behaviour
				// ALSO: Clear editable FLAG set due to 'apply' task
				if ( !$params->get('items_session_editable', 0) ) {
					$rendered_uneditable = $session->get('rendered_uneditable', array(),'flexicontent');
					if ( isset($rendered_uneditable[$model->get('id')]) ) {
						unset( $rendered_uneditable[$model->get('id')] );
						$session->set('rendered_uneditable', $rendered_uneditable, 'flexicontent');
					}
				}
			}
			
			else {
				// EXISTING ITEM: We can use editable till logoff behaviour
				if ( $params->get('items_session_editable', 0) ) {
					
					// Set notice for existing item being editable till logoff 
					JError::raiseNotice( 403, JText::_( 'FLEXI_CANNOT_EDIT_AFTER_LOGOFF' ) );
					
					// Allow item to be editable till logoff
					$rendered_uneditable = $session->get('rendered_uneditable', array(),'flexicontent');
					$rendered_uneditable[$model->get('id')]  = 1;
					$session->set('rendered_uneditable', $rendered_uneditable, 'flexicontent');
					$canEdit = 1;
				}
			}
			
			// Set notice about saving an item that cannot be changed further
			if ( !$canEdit ) {
				$app->enqueueMessage(JText::_( 'FLEXI_CANNOT_MAKE_FURTHER_CHANGES_TO_CONTENT' ), 'message' );
			}
		}
		
		
		// ****************************************************************
		// Check for new Content Item is being closed, and clear some flags
		// ****************************************************************
		
		if ($task!='apply' && $task!='apply_type' && $newly_submitted_item )
		{
			// Clear item from being marked as newly submitted
			unset($newly_submitted[$model->get('id')]);
			$session->set('newly_submitted', $newly_submitted, 'flexicontent');
			
			// Clear editable FLAG set temporarily, e.g. due to 'apply' task
			if ( !$params->get('items_session_editable', 0) ) {
				$rendered_uneditable = $session->get('rendered_uneditable', array(),'flexicontent');
				if ( isset($rendered_uneditable[$model->get('id')]) ) {
					unset( $rendered_uneditable[$model->get('id')] );
					$session->set('rendered_uneditable', $rendered_uneditable, 'flexicontent');
				}
			}
		}
		
		
		// ****************************************
		// Saving is done, decide where to redirect
		// ****************************************
		switch ($task)
		{
			case 'apply':
			case 'apply_type':
				$link = 'index.php?option=com_flexicontent&'.$ctrl_task.'edit&cid='.(int) $model->get('id');
				break;
			case 'saveandnew':
				$link = $type_id ?
					'index.php?option=com_flexicontent&view=item&typeid='.$type_id :
					'index.php?option=com_flexicontent&view=item' ;
				break;
			default:
				$link = 'index.php?option=com_flexicontent&view=items';
				break;
		}
		$msg = JText::_( 'FLEXI_ITEM_SAVED' );
		$this->setRedirect($link, $msg);
		
		//echo "</body></html>"; exit;
	}


	/**
	 * Logic to order up/down an item
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function reorder($dir=null)
	{
		// Check for request forgeries
		JRequest::checkToken() or jexit( 'Invalid Token' );
		
		// Get variables: model, user, item id
		$model = $this->getModel('items');
		$user  = JFactory::getUser();
		$cid   = JRequest::getVar( 'cid', array(0), 'default', 'array' );
		$ord_catid = JRequest::getVar( 'ord_catid', array(0), 'post', 'array' );
		$prev_order = JRequest::getVar( 'prev_order', array(0), 'post', 'array' );
		
		// calculate access
		$canOrder = $user->authorise('flexicontent.orderitems', 'com_flexicontent');
		
		// check access
		if ( !$canOrder ) {
			JError::raiseWarning( 403, JText::_( 'FLEXI_ALERTNOTAUTH' ) );
		} else if ( $model->move($dir, $ord_catid, $prev_order) ){
			// success
		} else {
			$msg = JText::_( 'FLEXI_ERROR_SAVING_ORDER' );
			JError::raiseWarning( 500, $model->getError() );
		}

		$this->setRedirect( 'index.php?option=com_flexicontent&view=items');
	}
	
	
	/**
	 * Logic to orderup an item
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function orderup()
	{
		$this->reorder($dir=-1);
	}

	/**
	 * Logic to orderdown an item
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function orderdown()
	{
		$this->reorder($dir=1);
	}
	
	
	/**
	 * Logic to mass ordering items
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function saveorder()
	{
		// Check for request forgeries
		JRequest::checkToken() or jexit( 'Invalid Token' );
		
		// Get variables: model, user, item id, new ordering
		$model = $this->getModel('items');
		$user  = JFactory::getUser();
		$cid   = JRequest::getVar( 'cid', array(0), 'default', 'array' );
		$order = JRequest::getVar( 'order', array(0), 'post', 'array' );
		$ord_catid = JRequest::getVar( 'ord_catid', array(0), 'post', 'array' );
		$prev_order = JRequest::getVar( 'prev_order', array(0), 'post', 'array' );
		
		// calculate access
		$canOrder = $user->authorise('flexicontent.orderitems', 'com_flexicontent');
		
		// check access
		if ( !$canOrder ) {
			JError::raiseWarning( 403, JText::_( 'FLEXI_ALERTNOTAUTH' ) );
		} else if (!$model->saveorder($cid, $order, $ord_catid, $prev_order)) {
			$msg = JText::_( 'FLEXI_ERROR_SAVING_ORDER' );
			JError::raiseWarning( 500, $msg ." " . $model->getError() );
			$msg = '';
		} else {
			$msg = JText::_( 'FLEXI_NEW_ORDERING_SAVED' );
		}

		$this->setRedirect( 'index.php?option=com_flexicontent&view=items', $msg );
	}


	/**
	 * Logic to display form for copy/move items
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function copy()
	{
		$db   = JFactory::getDBO();
		$user = JFactory::getUser();
		$cid  = JRequest::getVar( 'cid', array(0), 'post', 'array' );
		
		$canCopy = $user->authorise('flexicontent.copyitems', 'com_flexicontent');
		
		// check access of copy task
		if ( !$canCopy ) {
			JError::raiseWarning( 403, JText::_( 'FLEXI_ALERTNOTAUTH' ) );
			$this->setRedirect('index.php?option=com_flexicontent&view=items');
			return false;
		}
		
		// Access check
		$copytask_allow_uneditable = JComponentHelper::getParams( 'com_flexicontent' )->get('copytask_allow_uneditable', 1);
		if (!$copytask_allow_uneditable) {
			// Remove uneditable items
			$auth_cid = array();
			$non_auth_cid = array();
			
			// Get owner and other item data
			$q = "SELECT id, created_by, catid FROM #__content WHERE id IN (". implode(',', $cid) .")";
			$db->setQuery($q);
			$itemdata = $db->loadObjectList('id');
				
			// Check authorization for edit operation
			foreach ($cid as $id) {
				$rights 		= FlexicontentHelperPerm::checkAllItemAccess($user->id, 'item', $itemdata[$id]->id);
				$canEdit 		= in_array('edit', $rights);
				$canEditOwn = in_array('edit.own', $rights) && $itemdata[$id]->created_by == $user->id;
					
				if ( $canEdit || $canEditOwn ) {
					$auth_cid[] = $id;
				} else {
					$non_auth_cid[] = $id;
				}
			}
			//echo "<pre>"; echo "authorized:\n"; print_r($auth_cid); echo "\n\nNOT authorized:\n"; print_r($non_auth_cid); echo "</pre>"; exit;
		} else {
			$auth_cid = & $cid;
			$non_auth_cid = array();
		}
		
		// Set warning for uneditable items
		if (count($non_auth_cid)) {
			$msg_noauth = JText::_( 'FLEXI_CANNOT_COPY_ASSETS' );
			$msg_noauth .= ": " . implode(',', $non_auth_cid) ." - ". JText::_( 'FLEXI_REASON_NO_EDIT_PERMISSION' ) ." - ". JText::_( 'FLEXI_IDS_SKIPPED' );
			JError::raiseNotice(500, $msg_noauth);
			if ( !count($auth_cid) ) {  // Cancel task if no items can be copied
				$this->setRedirect('index.php?option=com_flexicontent&view=items');
				return false;
			}
		}
		
		// Set only authenticated item ids, to be used by the parent display method ...
		$cid = JRequest::setVar( 'cid', $auth_cid, 'post', 'array' );
		
		// display the form of the task
		parent::display();
	}
	
	/**
	 * Logic to copy/move the items
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function copymove()
	{
		// Check for request forgeries
		JRequest::checkToken() or jexit( 'Invalid Token' );

		$db    = JFactory::getDBO();
		$task  = JRequest::getVar('task');
		$model = $this->getModel('items');
		$user  = JFactory::getUser();
		$cid   = JRequest::getVar( 'cid', array(0), 'post', 'array' );
		$method   = JRequest::getInt( 'method', 1);
		$keeepcats= JRequest::getInt( 'keeepcats', 1 );
		$keeptags = JRequest::getInt( 'keeptags', 1 );
		$prefix   = JRequest::getVar( 'prefix', 1, 'post' );
		$suffix   = JRequest::getVar( 'suffix', 1, 'post' );
		$copynr   = JRequest::getInt( 'copynr', 1 );
		$maincat  = JRequest::getInt( 'maincat', '' );
		$seccats  = JRequest::getVar( 'seccats', array(), 'post', 'array' );
		$keepseccats = JRequest::getVar( 'keepseccats', 0, 'post', 'int' );
		$lang    = JRequest::getVar( 'language', '', 'post' );
		$state   = JRequest::getInt( 'state', '');
		
		// Set $seccats to --null-- to indicate that we will maintain secondary categories
		$seccats = $keepseccats ? null : $seccats;
		
		$canCopy = $user->authorise('flexicontent.copyitems', 'com_flexicontent');
		
		// check access of copy task
		if ( !$canCopy ) {
			JError::raiseWarning( 403, JText::_( 'FLEXI_ALERTNOTAUTH' ) );
			$this->setRedirect('index.php?option=com_flexicontent&view=items');
			return false;
		}
		
		// Access check
		$copytask_allow_uneditable = JComponentHelper::getParams( 'com_flexicontent' )->get('copytask_allow_uneditable', 1);
		if (!$copytask_allow_uneditable || $method==2) { // if method is 2 (move) we will deny moving uneditable items
			// Remove uneditable items
			$auth_cid = array();
			$non_auth_cid = array();
			
			// Get owner and other item data
			$q = "SELECT id, created_by, catid FROM #__content WHERE id IN (". implode(',', $cid) .")";
			$db->setQuery($q);
			$itemdata = $db->loadObjectList('id');
				
			// Check authorization for edit operation
			foreach ($cid as $id) {
				$rights 		= FlexicontentHelperPerm::checkAllItemAccess($user->id, 'item', $itemdata[$id]->id);
				$canEdit 		= in_array('edit', $rights);
				$canEditOwn = in_array('edit.own', $rights) && $itemdata[$id]->created_by == $user->id;
				
				if ( $canEdit || $canEditOwn ) {
					$auth_cid[] = $id;
				} else {
					$non_auth_cid[] = $id;
				}
			}
			//echo "<pre>"; echo "authorized:\n"; print_r($auth_cid); echo "\n\nNOT authorized:\n"; print_r($non_auth_cid); echo "</pre>"; exit;
		} else {
			$auth_cid = & $cid;
			$non_auth_cid = array();
		}
		
		// Set warning for uneditable items
		if (count($non_auth_cid)) {
			$msg_noauth = JText::_( 'FLEXI_CANNOT_COPY_ASSETS' );
			$msg_noauth .= ": " . implode(',', $non_auth_cid) ." - ". JText::_( 'FLEXI_REASON_NO_EDIT_PERMISSION' ) ." - ". JText::_( 'FLEXI_IDS_SKIPPED' );
			JError::raiseNotice(500, $msg_noauth);
			if ( !count($auth_cid) ) {  // Cancel task if no items can be copied
				$this->setRedirect('index.php?option=com_flexicontent&view=items');
				return false;
			}
		}
		
		// Set only authenticated item ids for the copyitems() method
		$auth_cid = $cid;
		$clean_cache_flag = false;
		
		// Try to copy/move items
		if ($task == 'copymove')
		{
			if ($method == 1) // copy only
			{
				if ( $model->copyitems($auth_cid, $keeptags, $prefix, $suffix, $copynr, $lang, $state) )
				{
					$msg = JText::sprintf( 'FLEXI_ITEMS_COPY_SUCCESS', count($auth_cid) );
					$clean_cache_flag = true;
				}
				else
				{
					$msg = JText::_( 'FLEXI_ERROR_COPY_ITEMS' );
					JError::raiseWarning( 500, $msg ." " . $model->getError() );
					$msg = '';
				}
			}
			else if ($method == 2) // move only
			{
				$msg = JText::sprintf( 'FLEXI_ITEMS_MOVE_SUCCESS', count($auth_cid) );
				
				foreach ($auth_cid as $itemid)
				{
					if ( !$model->moveitem($itemid, $maincat, $seccats) )
					{
						$msg = JText::_( 'FLEXI_ERROR_MOVE_ITEMS' );
						JError::raiseWarning( 500, $msg ." " . $model->getError() );
						$msg = '';
					}
				}
				
				$clean_cache_flag = true;
			}
			else // copy and move
			{
				if ( $model->copyitems($auth_cid, $keeptags, $prefix, $suffix, $copynr, $lang, $state, $method, $maincat, $seccats) )
				{
					$msg = JText::sprintf( 'FLEXI_ITEMS_COPYMOVE_SUCCESS', count($auth_cid) );
					$clean_cache_flag = true;
				}
				else
				{
					$msg = JText::_( 'FLEXI_ERROR_COPYMOVE_ITEMS' );
					JError::raiseWarning( 500, $msg ." " . $model->getError() );
					$msg = '';
				}
			}
			$link 	= 'index.php?option=com_flexicontent&view=items';
		}
		
		// CLEAN THE CACHE so that our changes appear realtime
		if ($clean_cache_flag) {
			$cache = FLEXIUtilities::getCache($group='', 0);
			$cache->clean('com_flexicontent_items');
			$cache->clean('com_flexicontent_filters');
			$cache = FLEXIUtilities::getCache($group='', 1);
			$cache->clean('com_flexicontent_items');
			$cache->clean('com_flexicontent_filters');
		}
		
		$this->setRedirect($link, $msg);
	}
	
	
	/**
	 * Check in a record
	 *
	 * @since	1.5
	 */
	function checkin()
	{
		$tbl = 'flexicontent_items';
		$redirect_url = 'index.php?option=com_flexicontent&view=items';
		flexicontent_db::checkin($tbl, $redirect_url, $this);
		return;// true;
	}
	
	
	/**
	 * Import Joomla com_content datas
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function import()
	{		
		// Check for request forgeries
		JRequest::checkToken( 'request' ) or jexit( 'Invalid Token' );

		$user  = JFactory::getUser();
		$model = $this->getModel('items');
		
		$permission = FlexicontentHelperPerm::getPerm();
		$canImport = $permission->CanConfig;
		
		if(!$canImport) {
			echo JText::_( 'ALERTNOTAUTH' );
			return;
		}
		
		$logs = $model->import();
		$cache = FLEXIUtilities::getCache($group='', 0);
		$cache->clean('com_flexicontent_cats');
		$cache = FLEXIUtilities::getCache($group='', 1);
		$cache->clean('com_flexicontent_cats');
		
		$msg  = JText::_( 'FLEXI_IMPORT_SUCCESSFUL' );
		$msg .= '<ul class="import-ok">';
		if (!FLEXI_J16GE) {
			$msg .= '<li>' . $logs->sec . ' ' . JText::_( 'FLEXI_IMPORT_SECTIONS' ) . '</li>';
		}
		$msg .= '<li>' . $logs->cat . ' ' . JText::_( 'FLEXI_IMPORT_CATEGORIES' ) . '</li>';
		$msg .= '<li>' . $logs->art . ' ' . JText::_( 'FLEXI_IMPORT_ARTICLES' ) . '</li>';
		$msg .= '</ul>';

		if (isset($logs->err)) {
			$msg .= JText::_( 'FLEXI_IMPORT_FAILED' );
			$msg .= '<ul class="import-failed">';
			foreach ($logs->err as $err) {
				$msg .= '<li>' . $err->type . ' ' . $err->id . ': ' . $err->title . '</li>';
			}
			$msg .= '</ul>';
		} else {
			$msg .= JText::_( 'FLEXI_IMPORT_NO_ERROR' );		
		}
    
		$msg .= '<p class="button-close"><input type="button" class="fc_button" onclick="window.parent.document.adminForm.submit();" value="'.JText::_( 'FLEXI_CLOSE' ).'" /><p>';

		echo $msg;
	}
	
	

	/**
	 * Logic to change state of multiple items
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function changestate()
	{
		$db    = JFactory::getDBO();
		$user  = JFactory::getUser();
		$cid   = JRequest::getVar( 'cid', array(), 'post', 'array' );
		$model = $this->getModel('item');
		$msg = '';
		
		$newstate = JRequest::getVar("newstate", '');
		$stateids = array ( 'PE' => -3, 'OQ' => -4, 'IP' => -5, 'P' => 1, 'U' => 0, 'A' => (FLEXI_J16GE ? 2:-1), 'T' => -2 );
		$statenames = array ( 'PE' => 'FLEXI_PENDING', 'OQ' => 'FLEXI_TO_WRITE', 'IP' => 'FLEXI_IN_PROGRESS', 'P' => 'FLEXI_PUBLISHED', 'U' => 'FLEXI_UNPUBLISHED', 'A' => 'FLEXI_ARCHIVED', 'T' => 'FLEXI_TRASHED' );
		
		// check valid state
		if ( !isset($stateids[$newstate]) ) {
			JError::raiseWarning(500, JText::_( 'Invalid State' ).": ".$newstate );
		}
		
		// check at least one item was selected
		if ( !count( $cid ) ) {
			JError::raiseWarning(500, JText::_( 'FLEXI_NO_ITEMS_SELECTED' ) );
		} else {
			// Remove unauthorized (undeletable) items
			$auth_cid = array();
			$non_auth_cid = array();
			
			// Get owner and other item data
			$q = "SELECT id, created_by, catid FROM #__content WHERE id IN (". implode(',', $cid) .")";
			$db->setQuery($q);
			$itemdata = $db->loadObjectList('id');
			
			// Check authorization for publish operation
			foreach ($cid as $id) {
				
				// Determine priveleges of the current user on the given item
				$asset = 'com_content.article.' . $itemdata[$id]->id;
				$has_edit_state = $user->authorise('core.edit.state', $asset) || ($user->authorise('core.edit.state.own', $asset) && $itemdata[$id]->created_by == $user->get('id'));
				$has_delete     = $user->authorise('core.delete', $asset) || ($user->authorise('core.delete.own', $asset) && $itemdata[$id]->created_by == $user->get('id'));
				// ...
				$permission = FlexicontentHelperPerm::getPerm();
				$has_archive    = $permission->CanArchives;
				
				$has_edit_state = $has_edit_state && in_array($stateids[$newstate], array(0,1,-3,-4,-5));
				$has_delete     = $has_delete     && $stateids[$newstate] == -2;
				$has_archive    = $has_archive    && $stateids[$newstate] == (FLEXI_J16GE ? 2:-1);
				
				if ( $has_edit_state || $has_delete || $has_archive ) {
					$auth_cid[] = $id;
				} else {
					$non_auth_cid[] = $id;
				}
			}
		}

		//echo "<pre>"; echo "authorized:\n"; print_r($auth_cid); echo "\n\nNOT authorized:\n"; print_r($non_auth_cid); echo "</pre>"; exit;
		
		// Set warning for undeletable items
		if (count($non_auth_cid)) {
			$msg_noauth = JText::_( 'FLEXI_CANNOT_CHANGE_STATE_ASSETS' );
			$msg_noauth .= ": " . implode(',', $non_auth_cid) ." - ". JText::_( 'FLEXI_REASON_NO_PUBLISH_PERMISSION' ) ." - ". JText::_( 'FLEXI_IDS_SKIPPED' );
			JError::raiseNotice(500, $msg_noauth);
		}
		
		// Set state
		if ( count($auth_cid) ){
			foreach ($auth_cid as $item_id) {
				$model->setitemstate($item_id, $stateids[$newstate]);
			}
			$msg = count($auth_cid) ." ". JText::_('FLEXI_ITEMS') ." : &nbsp; ". JText::_( 'FLEXI_ITEMS_STATE_CHANGED_TO')." -- ".JText::_( $statenames[$newstate] ) ." --";
		}

		$cache = FLEXIUtilities::getCache($group='', 0);
		$cache->clean('com_flexicontent_items');
		$cache->clean('com_flexicontent_filters');
		$cache = FLEXIUtilities::getCache($group='', 1);
		$cache->clean('com_flexicontent_items');
		$cache->clean('com_flexicontent_filters');
		
		$this->setRedirect( 'index.php?option=com_flexicontent&view=items', $msg );
	}

	
	/**
	 * Logic to submit item to approval
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function approval()
	{
		$cid	= JRequest::getVar( 'cid', array(0), 'post', 'array' );
		if (!is_array( $cid ) || count( $cid ) < 1) {
			$msg = '';
			JError::raiseWarning(500, JText::_( 'FLEXI_APPROVAL_SELECT_ITEM_SUBMIT' ) );
		} else {
			$itemmodel = $this->getModel('item');
			$msg = $itemmodel->approval($cid);
		}
		
		$this->setRedirect( 'index.php?option=com_flexicontent&view=items', $msg );
	}
	
	
	/**
	 * Logic to delete items
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function remove()
	{
		// Check for request forgeries
		JRequest::checkToken() or jexit( 'Invalid Token' );
		
		$db    = JFactory::getDBO();
		$user  = JFactory::getUser();
		$cid   = JRequest::getVar( 'cid', array(0), 'post', 'array' );
		$model = $this->getModel('items');
		$itemmodel = $this->getModel('item');
		$msg = '';

		if (!is_array( $cid ) || count( $cid ) < 1) {
			JError::raiseNotice(500, JText::_( 'FLEXI_SELECT_ITEM_DELETE' ) );
		} else {
			// Remove unauthorized (undeletable) items
			$auth_cid = array();
			$non_auth_cid = array();
			
			// Get owner and other item data
			$q = "SELECT id, created_by, catid FROM #__content WHERE id IN (". implode(',', $cid) .")";
			$db->setQuery($q);
			$itemdata = $db->loadObjectList('id');
			
			// Check authorization for delete operation
			foreach ($cid as $id) {
			
				$rights 		= FlexicontentHelperPerm::checkAllItemAccess($user->id, 'item', $itemdata[$id]->id);
				$canDelete 		= in_array('delete', $rights);
				$canDeleteOwn = in_array('delete.own', $rights) && $itemdata[$id]->created_by == $user->id;
				
				if ( $canDelete || $canDeleteOwn ) {
					$auth_cid[] = $id;
				} else {
					$non_auth_cid[] = $id;
				}
			}
		}
		//echo "<pre>"; echo "authorized:\n"; print_r($auth_cid); echo "\n\nNOT authorized:\n"; print_r($non_auth_cid); echo "</pre>"; exit;
		
		// Set warning for undeletable items
		if (count($non_auth_cid)) {
			if (count($non_auth_cid) < 2) {
				$msg_noauth = JText::_( 'FLEXI_CANNOT_DELETE_ITEM' );
			} else {
				$msg_noauth = JText::_( 'FLEXI_CANNOT_DELETE_ITEMS' );
			}
			$msg_noauth .= ": " . implode(',', $non_auth_cid) ." - ". JText::_( 'FLEXI_REASON_NO_DELETE_PERMISSION' ) ." - ". JText::_( 'FLEXI_IDS_SKIPPED' );
			JError::raiseNotice(500, $msg_noauth);
		}
		
		// Try to delete 
		if ( count($auth_cid) && !$model->delete($auth_cid, $itemmodel) ) {
			JError::raiseWarning(500, JText::_( 'FLEXI_OPERATION_FAILED' ));
		} else {
			$msg = count($auth_cid).' '.JText::_( 'FLEXI_ITEMS_DELETED' );
			$cache = FLEXIUtilities::getCache($group='', 0);
			$cache->clean('com_flexicontent_items');
			$cache->clean('com_flexicontent_filters');
			$cache = FLEXIUtilities::getCache($group='', 1);
			$cache->clean('com_flexicontent_items');
			$cache->clean('com_flexicontent_filters');
		}
		
		$this->setRedirect( 'index.php?option=com_flexicontent&view=items', $msg );
	}

	/**
	 * Logic to set the access level of the Items
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function access( )
	{
		// Check for request forgeries
		JRequest::checkToken() or jexit( 'Invalid Token' );
		
		$user	= JFactory::getUser();
		$cid  = JRequest::getVar( 'cid', array(0), 'post', 'array' );
		$id   = (int)$cid[0];
		$task = JRequest::getVar( 'task' );
		
		// Decide / Retrieve new access level
		$accesses	= JRequest::getVar( 'access', array(0), 'post', 'array' );
		$access = $accesses[$id];
		
		$model = $this->getModel('item');
		
		$canEdit = !FLEXI_J16GE ? $model->canEdit() : $model->getItemAccess()->get('access-edit');
		
		// Check if user can edit the item
		if ( !$canEdit ) {
			$msg_noauth = JText::_( 'FLEXI_CANNOT_CHANGE_ACCLEVEL_ASSETS' );
			$msg_noauth .= ": " . implode(',', $non_auth_cid) ." - ". JText::_( 'FLEXI_REASON_NO_PUBLISH_PERMISSION' );
		}
		if ($msg_noauth) {
			JError::raiseNotice(500, $msg_noauth);
			$this->setRedirect( 'index.php?option=com_flexicontent&view=items', '');
			return;
		}

		$model = $this->getModel('items');
		
		if(!$model->saveaccess( $id, $access )) {
			$msg = JText::_( 'FLEXI_ERROR_SETTING_ITEM_ACCESS_LEVEL' );
			JError::raiseWarning( 500, $msg ." " . $model->getError() );
			$msg = '';
		} else {
			$cache = FLEXIUtilities::getCache($group='', 0);
			$cache->clean('com_flexicontent_items');
			$cache->clean('com_flexicontent_filters');
			$cache = FLEXIUtilities::getCache($group='', 1);
			$cache->clean('com_flexicontent_items');
			$cache->clean('com_flexicontent_filters');
		}
		
		$this->setRedirect('index.php?option=com_flexicontent&view=items' );
	}

	/**
	 * logic for cancel an action
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function cancel()
	{
		// Check for request forgeries
		JRequest::checkToken() or jexit( 'Invalid Token' );
		
		$post = JRequest::get('post');
		$post = FLEXI_J16GE ? $post['jform'] : $post;
		JRequest::setVar('cid', $post['id']);
		$this->checkin();
	}

	/**
	 * logic for restore an old version
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function restore()
	{
		// Check for request forgeries
		JRequest::checkToken( 'request' ) or jexit( 'Invalid Token' );

		$id			= JRequest::getInt( 'id', 0 );
		$version	= JRequest::getVar( 'version', '', 'request', 'int' );
		$model		= $this->getModel('item');

		// First checkin the open item
		$item = JTable::getInstance('flexicontent_items', '');
		$item->bind(JRequest::get('request'));
		$item->checkin();
		if ($version) {
			$msg = JText::sprintf( 'FLEXI_VERSION_RESTORED', $version );
			$model->restore($version, $id);
		} else {
			$msg = JText::_( 'FLEXI_NOTHING_TO_RESTORE' );
		}
		$ctrlTask  = !FLEXI_J16GE ? 'controller=items&task=edit' : 'task=items.edit';
		$this->setRedirect( 'index.php?option=com_flexicontent&'.$ctrlTask.'&cid[]='.$id, $msg );
	}


	/**
	 * Logic to create the view for the edit item screen
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function edit()
	{
		JRequest::setVar( 'view', 'item' );
		JRequest::setVar( 'hidemainmenu', 1 );

		$user     = JFactory::getUser();
		$session  = JFactory::getSession();
		$document = JFactory::getDocument();
		
		// Get/Create the view
		$viewType   = $document->getType();
		$viewName   = FLEXI_J30GE ? $this->input->get('view', $this->default_view) : JRequest::getVar('view');
		$viewLayout = FLEXI_J30GE ? $this->input->get('layout', 'default', 'string') : JRequest::getVar('layout', 'default', 'string');
		$view = $this->getView($viewName, $viewType, '', array('base_path' => $this->basePath, 'layout' => $viewLayout));
		
		// Get/Create the model
		$model = $this->getModel('item');
		
		// Push the model into the view (as default), later we will call the view display method instead of calling parent's display task, because it will create a 2nd model instance !!
		$view->setModel($model, true);
		$view->document = $document;
		
		// FORCE model to load versioned data (URL specified version or latest version (last saved))
		$version = JRequest::getVar( 'version', 0, 'request', 'int' );   // Load specific item version (non-zero), 0 version: is unversioned data, -1 version: is latest version (=default for edit form)
		$item = $model->getItem(null, $check_view_access=false, $no_cache=true, $force_version=($version!=0 ? $version : -1));  // -1 version means latest
		
		$isnew  = !$model->getId();
		
		$canAdd  = !FLEXI_J16GE ? $model->canAdd()  : $model->getItemAccess()->get('access-create');
		$canEdit = !FLEXI_J16GE ? $model->canEdit() : $model->getItemAccess()->get('access-edit');

		if ( !$canEdit ) {
			// No edit privilege, check if item is editable till logoff
			if ($session->has('rendered_uneditable', 'flexicontent')) {
				$rendered_uneditable = $session->get('rendered_uneditable', array(),'flexicontent');
				$canEdit = isset($rendered_uneditable[$model->get('id')]) && $rendered_uneditable[$model->get('id')];
			}
		}
		
		// New item: check if user can create in at least one category
		if ($isnew) {
			
			// A. Check create privilege
			if( !$canAdd ) {
				JError::raiseNotice( 403, JText::_( 'FLEXI_NO_ACCESS_CREATE' ));
				$this->setRedirect( 'index.php?option=com_flexicontent&view=items', '');
				return;
			}
			
			// Get User Group / Author parameters
			$db = JFactory::getDBO();
			$db->setQuery('SELECT author_basicparams FROM #__flexicontent_authors_ext WHERE user_id = ' . $user->id);
			$authorparams = $db->loadResult();
			$authorparams = FLEXI_J16GE ? new JRegistry($authorparams) : new JParameter($authorparams);
			$max_auth_limit = $authorparams->get('max_auth_limit', 0);  // maximum number of content items the user can create
			
			// B. Check if max authored content limit reached
			if ($max_auth_limit) {
				$db->setQuery('SELECT COUNT(id) FROM #__content WHERE created_by = ' . $user->id);
				$authored_count = $db->loadResult();
				if ($authored_count >= $max_auth_limit) {
					JError::raiseNotice( 403, JText::sprintf( 'FLEXI_ALERTNOTAUTH_CREATE_MORE', $max_auth_limit ) );
					$this->setRedirect( 'index.php?option=com_flexicontent&view=items', '' );
					return;
				}
			}
			
			// C. Check if Content Type can be created by current user
			$typeid = JRequest::getVar('typeid', 0, '', 'int');
			if ($typeid) {
				$canCreateType = $model->canCreateType( array($typeid), true, $types ); // Can create given Content Type
			} else {
				$canCreateType = $model->canCreateType( );  // Can create at least one Content Type
			}
			
			if( !$canCreateType ) {
				$type_name = isset($types[$$typeid]) ? '"'.JText::_($types[$$typeid]->name).'"' : JText::_('FLEXI_ANY');
				$msg = JText::sprintf( 'FLEXI_NO_ACCESS_CREATE_CONTENT_OF_TYPE', $type_name );
				JError::raiseNotice( 403, $msg );
				$this->setRedirect( 'index.php?option=com_flexicontent&view=items', '');
				return;
			}
		}
		
		// Existing item: Check if user can edit current item
		else {
			if ( !$canEdit ) {
				JError::raiseNotice( 403, JText::_( 'FLEXI_NO_ACCESS_EDIT' ));
				$this->setRedirect( 'index.php?option=com_flexicontent&view=items', '');
				return;
			}
		}
		
		// Check if record is checked out by other editor
		if ( $model->isCheckedOut( $user->get('id') ) ) {
			JError::raiseNotice( 500, JText::_( 'FLEXI_EDITED_BY_ANOTHER_ADMIN' ));
			$this->setRedirect( 'index.php?option=com_flexicontent&view=items', '');
			return;
		}
		
		// Checkout the record and proceed to edit form
		if ( !$model->checkout() ) {
			JError::raiseWarning( 500, $model->getError() );
			$this->setRedirect( 'index.php?option=com_flexicontent&view=items', '');
			return;
		}
		
		// Call display method of the view, instead of calling parent's display task, because it will create a 2nd model instance !!
		$view->display();
		//parent::display();
	}

	/**
	 * Method to fetch the tags form
	 * 
	 * @since 1.5
	 */
	function gettags()
	{
		$id    = JRequest::getInt('id', 0);
		$model = $this->getModel('item');
		$tags  = $model->gettags();
		$user  = JFactory::getUser();
		
		$used = null;

		if ($id) {
			$used = $model->getUsedtagsIds($id);
		}
		if(!is_array($used)){
			$used = array();
		}
		
		$permission = FlexicontentHelperPerm::getPerm();
		$CanNewTags = $permission->CanNewTags;
		$CanUseTags = $permission->CanUseTags;

		$CanUseTags = $CanUseTags ? '' : ' disabled="disabled"';
		$n = count($tags);
		$rsp = '';
		if ($n>0) {
			$rsp .= '<div class="qf_tagbox">';
			$rsp .= '<ul>';
			for( $i = 0, $n; $i < $n; $i++ ){
				$tag = $tags[$i];
				$rsp .=  '<li><div><span class="qf_tagidbox"><input type="checkbox" name="tag[]" value="'.$tag->id.'"' . (in_array($tag->id, $used) ? 'checked="checked"' : '') . $CanUseTags . ' /></span>'.$tag->name.'</div></li>';
				if ($CanUseTags && in_array($tag->id, $used)){
					$rsp .= '<input type="hidden" name="tag[]" value="'.$tag->id.'" />';
				}
			}
			$rsp .= '</ul>';
			$rsp .= '</div>';
			$rsp .= '<div class="clear"></div>';
			}
		if ($CanNewTags)
		{
			$rsp .= '<div class="qf_addtag">';
			$rsp .= '<label for="addtags">'.JText::_( 'FLEXI_ADD_TAG' ).'</label>';
			$rsp .= '<input type="text" id="tagname" class="inputbox" size="30" />';
			$rsp .=	'<input type="button" class="fc_button" value="'.JText::_( 'FLEXI_ADD' ).'" onclick="addtag()" />';
			$rsp .= '</div>';
		}
		echo $rsp;
	}
	

	/**
	 * Method to fetch the votes
	 * 
	 * @since 1.5
	 */
	function getvotes()
	{
		$id 	= JRequest::getInt('id', 0);
		$model 	= $this->getModel('item');
		$votes 	= $model->getvotes($id);
		$db = JFactory::getDBO();
		
		$db->setQuery('SELECT * FROM #__flexicontent_fields WHERE field_type="voting"');
		$field = $db->loadObject();
		$item = JTable::getInstance( $type = 'flexicontent_items', $prefix = '', $config = array() );
		$item->load( $id );
		FlexicontentFields::loadFieldConfig($field, $item);
		
		$rating_resolution = (int)$field->parameters->get('rating_resolution', 5);
		$rating_resolution = $rating_resolution >= 5   ?  $rating_resolution  :  5;
		$rating_resolution = $rating_resolution <= 100  ?  $rating_resolution  :  100;
		
		
		@ob_end_clean();
		if ($votes) {
			$score	= round((((int)$votes[0]->rating_sum / (int)$votes[0]->rating_count) * (100 / $rating_resolution)), 2);
			$vote	= ((int)$votes[0]->rating_count > 1) ? (int)$votes[0]->rating_count . ' ' . JText::_( 'FLEXI_VOTES' ) : (int)$votes[0]->rating_count . ' ' . JText::_( 'FLEXI_VOTE' );
			echo $score.'% | '.$vote;
		} else {
			echo JText::_( 'FLEXI_NOT_RATED_YET' );
		}
		exit;
	}

	/**
	 * Method to get hits
	 * 
	 * @since 1.0
	 */
	function gethits()
	{
		$id 	= JRequest::getInt('id', 0);
		$model 	= $this->getModel('item');

		@ob_end_clean();
		$hits 	= $model->gethits($id);

		if ($hits) {
			echo $hits;
		} else {
			echo 0;
		}
		exit;
	}

	
	
}
