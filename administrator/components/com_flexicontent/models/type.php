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

// no direct access
defined('_JEXEC') or die('Restricted access');

jimport('joomla.application.component.model');

/**
 * FLEXIcontent Component Type Model
 *
 * @package Joomla
 * @subpackage FLEXIcontent
 * @since		1.0
 */
class FlexicontentModelType extends JModel
{
	/**
	 * Type primary key
	 *
	 * @var int
	 */
	var $_id = null;
	
	/**
	 * Type data
	 *
	 * @var object
	 */
	var $_type = null;

	/**
	 * Constructor
	 *
	 * @since 1.0
	 */
	function __construct()
	{
		parent::__construct();

		$array = JRequest::getVar('cid',  array(0), '', 'array');
		$array = is_array($array) ? $array : array($array);
		$id = $array[0];
		if(!$id) {
			$post = JRequest::get( 'post' );
			$data = FLEXI_J16GE ? @$post['jform'] : $post;
			$id = @$data['id'];
		}
		$this->setId((int)$id);
	}

	/**
	 * Method to set the identifier
	 *
	 * @access	public
	 * @param	int type identifier
	 */
	function setId($id)
	{
		// Set type id and wipe data
		$this->_id     = $id;
		$this->_type   = null;
	}
	

	/**
	 * Method to get the type identifier
	 *
	 * @access	public
	 */
	function getId() {
		return $this->_id;
	}
	
	/**
	 * Overridden get method to get properties from the type
	 *
	 * @access	public
	 * @param	string	$property	The name of the property
	 * @param	mixed	$value		The value of the property to set
	 * @return 	mixed 				The value of the property
	 * @since	1.0
	 */
	function get($property, $default=null)
	{
		if ($this->_loadType())
		{
			if(isset($this->_type->$property)) {
				return $this->_type->$property;
			}
		}
		return $default;
	}

	/**
	 * Method to get type data
	 *
	 * @access	public
	 * @return	array
	 * @since	1.0
	 */
	function &getType()
	{
		if ($this->_loadType()) {
			// extra steps after loading
			// ...
		} else {
			$this->_initType();
		}
		
		return $this->_type;
	}


	/**
	 * Method to load type data
	 *
	 * @access	private
	 * @return	boolean	True on success
	 * @since	1.0
	 */
	function _loadType()
	{
		// Lets load the type if it doesn't already exist
		if ( $this->_type===null )
		{
			$query = 'SELECT *'
					. ' FROM #__flexicontent_types'
					. ' WHERE id = '.$this->_id
					;
			$this->_db->setQuery($query);
			$this->_type = $this->_db->loadObject();

			return (boolean) $this->_type;
		}
		return true;
	}

	/**
	 * Method to initialise the type data
	 *
	 * @access	private
	 * @return	boolean	True on success
	 * @since	1.0
	 */
	function _initType()
	{
		// Lets load the type if it doesn't already exist
		if ( $this->_type===null )
		{
			$type = new stdClass();
			$type->id					= 0;
			$type->name				= null;
			$type->alias			= null;
			$type->published	= 1;
			$type->attribs		= null;
			$this->_type			= $type;
			return (boolean) $this->_type;
		}
		return true;
	}

	/**
	 * Method to checkin/unlock the type
	 *
	 * @access	public
	 * @return	boolean	True on success
	 * @since	1.0
	 */
	function checkin()
	{
		if ($this->_id)
		{
			$instance = & JTable::getInstance('flexicontent_types', '');
			$user = &JFactory::getUser();
			return $instance->checkout($user->get('id'), $this->_id);
		}
		return false;
	}

	/**
	 * Method to checkout/lock the type
	 *
	 * @access	public
	 * @param	int	$uid	User ID of the user checking the item out
	 * @return	boolean	True on success
	 * @since	1.0
	 */
	function checkout($uid = null)
	{
		if ($this->_id)
		{
			// Make sure we have a user id to checkout the group with
			if (is_null($uid)) {
				$user	=& JFactory::getUser();
				$uid	= $user->get('id');
			}
			// Lets get to it and checkout the thing...
			$instance = & JTable::getInstance('flexicontent_types', '');
			return $instance->checkout($uid, $this->_id);
		}
		return false;
	}

	/**
	 * Tests if the type is checked out
	 *
	 * @access	public
	 * @param	int	A user id
	 * @return	boolean	True if checked out
	 * @since	1.0
	 */
	function isCheckedOut( $uid=0 )
	{
		if ($this->_loadType())
		{
			if ($uid) {
				return ($this->_type->checked_out && $this->_type->checked_out != $uid);
			} else {
				return $this->_type->checked_out;
			}
		} elseif ($this->_id < 1) {
			return false;
		} else {
			JError::raiseWarning( 0, 'UNABLE LOAD DATA');
			return false;
		}
	}

	/**
	 * Method to store the type
	 *
	 * @access	public
	 * @return	boolean	True on success
	 * @since	1.0
	 */
	function store($data)
	{
		// Check for request forgeries
		JRequest::checkToken() or jexit( 'Invalid Token' );

		// NOTE: 'data' is post['jform'] for J2.5 (this is done by the controller or other caller)
		$type  =& $this->getTable('flexicontent_types', '');
		
		// bind it to the table
		if (!$type->bind($data)) {
			$this->setError( $this->_db->getErrorMsg() );
			return false;
		}
		
		// Get field attibutes, for J1.5 is params for J2.5 is attribs
		$attibutes = !FLEXI_J16GE ? $data['params'] : $data['attribs'];

		// Build attibutes INI string
		if (is_array($attibutes))
		{
			$txt = array ();
			foreach ($attibutes as $k => $v) {
				if (is_array($v)) {
					$v = implode('|', $v);
				}
				$txt[] = "$k=$v";
			}
			$type->attribs = implode("\n", $txt);
		}

		// Make sure the data is valid
		if (!$type->check()) {
			$this->setError($type->getError() );
			return false;
		}

		// Store it in the db
		if (!$type->store()) {
			$this->setError( $this->_db->getErrorMsg() );
			return false;
		}
		
		$this->_type = & $type;
		$this->_id   = $type->id;
		
		// Only insert default relations if the type is new
		if ( $insertid = (int)$this->_db->insertid() )
			$this->_addCoreFieldRelations();
		
		return true;
	}
	
	/**
	 * Method to add core field relation to a type
	 *
	 * @access	private
	 * @return	boolean	True on success
	 * @since	1.0
	 */
	function _addCoreFieldRelations()
	{
		$type = & $this->_type;
		
		// Get core fields
		$core = $this->_getCoreFields();
		
		// Insert core field relations to the DB
		foreach ($core as $fieldid) {
			$obj = new stdClass();
			$obj->field_id  = (int)$fieldid;
			$obj->type_id   = $type->id;
			$this->_db->insertObject('#__flexicontent_fields_type_relations', $obj);
		}
	}

	
	function addtype($name) {
		
		$obj = new stdClass();
		$obj->name	 	= $name;
		$obj->published	= 1;
		
		$this->store($obj);
		return true;
	}

	function _getCoreFields(){
		
		$query = 'SELECT id'
				. ' FROM #__flexicontent_fields'
				. ' WHERE iscore = 1'
				;
		$this->_db->setQuery($query);
		$corefields = $this->_db->loadResultArray();
		
		return $corefields;
	}

}
?>
