<?php
namespace asinfotrack\yii2\audittrail\behaviors;

use yii\db\ActiveRecord;
use yii\base\InvalidConfigException;
use yii\base\InvalidValueException;
use asinfotrack\yii2\audittrail\models\AuditTrailEntry;

/**
 * Behavior which enables a model to be audited. Each modification (insert, update and delete)
 * will be logged with the changed field values. 
 * To enable the behavior on a model simply add it to its behaviors. Further configuration is
 * possible. Check out this classes attributes to see what options there are.
 * 
 * @author Pascal Mueller, AS infotrack AG
 * @link http://www.asinfotrack.ch
 * @license MIT
 */
class AuditTrailBehavior extends \yii\base\Behavior
{
	
	//constants for entry-types
	const AUDIT_TYPE_INSERT = 'insert';
	const AUDIT_TYPE_UPDATE = 'update';
	const AUDIT_TYPE_DELETE = 'delete';
	
	/**
	 * @var string[] holds all allowed audit types
	 */
	public static $AUDIT_TYPES = [self::AUDIT_TYPE_INSERT, self::AUDIT_TYPE_UPDATE, self::AUDIT_TYPE_DELETE];
	
	/**
	 * @var string[] if defined, the listed attributes will be ignored. Good examples for
	 * fields to ignore would be the db-fields of TimestampBehavior or BlameableBehavior.
	 */
	public $ignoredAttributes = [];
	
	/**
	 * @var \Closure|null optoinal closure to return the timestamp of an event. It needs to be
	 * in the format 'function() { }' returning an integer. If not set 'time()' is used.
	 */
	public $timestampCallback = null;
	
	/**
	 * @var integer|\Closure|null the user id to use if console actions modify a model.
	 * If a closure is used, use the 'function() { }' and return an integer or null. 
	 */
	public $consoleUserId = null;
	
	/**
	 * @var boolean if set to true, the data fields will be persisted upon insert. Defaults to true.
	 */
	public $persistValuesOnInsert = true;
	
	/**
	 * @var boolean if set to true, inserts will be logged (default: true)
	 */
	public $logInsert = true;
	
	/**
	 * @var boolean if set to true, updates will be logged (default: true)
	 */
	public $logUpdate = true;
	
	/**
	 * @var boolean if set to true, deletes will be logged (default: true)
	 */
	public $logDelete = true;
	
	/**
	 * (non-PHPdoc)
	 * @see \yii\base\Behavior::attach()
	 */
	public function attach($owner)
	{
		//assert owner extends class ActiveRecord
		if (!($owner instanceof ActiveRecord)) {
			throw new InvalidConfigException('The HistoryBehavior can only be applied to classes extending \yii\db\ActiveRecord');
		}
	
		parent::attach($owner);
	}
	
	/**
	 * (non-PHPdoc)
	 * @see \yii\base\Behavior::events()
	 */
	public function events()
	{
		return [
			ActiveRecord::EVENT_AFTER_INSERT  => 'onAfterInsert',
			ActiveRecord::EVENT_AFTER_UPDATE  => 'onAfterUpdate',
			ActiveRecord::EVENT_BEFORE_DELETE => 'onBeforeDelete',
		];
	}
	
	/**
	 * Handler for after insert event
	 * 
	 * @param \yii\db\AfterSaveEvent $event the after save event
	 */
	public function onAfterInsert($event)
	{
		if (!$this->logInsert) return;
		$entry = $this->createPreparedAuditTrailEntry(self::AUDIT_TYPE_INSERT);
		
		//if configured write initial values
		if ($this->persistValuesOnInsert) {
			foreach ($this->getRelevantDbAttributes() as $attrName) {
				$entry->data[] = $this->createAttrChangeObj($attrName, null, $this->owner->{$attrName}); 
			}
		}
		
		static::saveEntry($entry);
	}
	
	/**
	 * Handler for after update event
	 * 
	 * @param \yii\db\AfterSaveEvent $event the after save event
	 */
	public function onAfterUpdate($event)
	{
		if (!$this->logUpdate) return;		
		$entry = $this->createPreparedAuditTrailEntry(self::AUDIT_TYPE_UPDATE);
		
		//fetch dirty attributes and add changes
		$relevantAttrs = $this->getRelevantDbAttributes();
		foreach ($event->changedAttributes as $attrName=>$oldVal) {
			//skip if ignored
			if (!in_array($attrName, $relevantAttrs)) continue;
			//add change
			$entry->data[] = $this->createAttrChangeObj($attrName, $oldVal, $this->owner->{$attrName});
		}
		
		static::saveEntry($entry);
	}
	
	/**
	 * Handler for before delete event
	 */
	public function onBeforeDelete()
	{
		if (!$this->logDelete) return;
		$entry = $this->createPreparedAuditTrailEntry(self::AUDIT_TYPE_DELETE);

		static::saveEntry($entry);
	}
	
	/**
	 * Creates and returns a preconfigured history-entry-model
	 * 
	 * @param string $changeKind the kind of history entry (use this classes statics)
	 * @return \asinfotrack\yii2\history\models\HistoryEntry
	 */
	protected function createPreparedAuditTrailEntry($changeKind)
	{
		return new HistoryEntry([
			'model_type'=>$this->owner->className(),
			'foreign_pk'=>$this->createPrimaryKeyJson(),
			'happened_at'=>$this->getHappenedAt(),
			'user_id'=>$this->getUserId(),
			'type'=>$changeKind,
			'data'=>[],
		]);
	}
	
	/**
	 * Creates an attribute change object which will later be persisted in json-format
	 * 
	 * @param string $attr name of the attribute
	 * @param mixed $from old value
	 * @param mixed $to new value
	 * @return \stdClass the prepared attr change object
	 */
	protected function createAttrChangeObj($attr, $from, $to)
	{
		$obj = new \stdClass();
		$obj->attr = $attr;
		$obj->from = $from;
		$obj->to = $to;
		return $obj;
	}
	
	/**
	 * Returns the user id to use for a history-entry
	 * 
	 * @return integer|null returns either a user id or null.
	 */
	protected function getUserId()
	{
		if (Yii::$app instanceof \yii\console\Application) {
			if ($this->consoleUserId instanceof \Closure) {
				return call_user_func($this->consoleUserId);
			} else {
				return $this->consoleUserId;
			}
		} else if (Yii::$app->user->isGuest) {
			return null;
		} else {
			return Yii::$app->user->id;
		}
	}
	
	/**
	 * Returns the timestamp for the history-entry.
	 * 
	 * @return integer unix-timestamp
	 */
	protected function getHappenedAt()
	{
		if ($this->timestampCallback !== null) {
			return call_user_func($this->timestampCallback);
		} else {
			return time();
		}
	}
	
	/**
	 * Creates the json-represenation of the pk (array in the format attribute=>value
	 * 
	 * @return string json-representation of the pk-array
	 * @throws InvalidConfigException if the returned pk is null
	 */
	protected function createPrimaryKeyJson()
	{
		//fetch the objects pk	
		$pk = $this->owner->primaryKey;
		if ($pk === null) {
			throw new InvalidConfigException('pk NULL received: please provide a pk-definition for table ' . $this->owner->tableName());
		}
		//create array if it is not already
		if (!is_array($pk)) $pk = [$pk=>$this->owner->{$pk}];

		return Json::encode($pk);
	}
	
	/**
	 * This method is responsible to create a list of relevant db-columns to track. The ones
	 * listed to exlude will be removed here already.
	 * 
	 * @return string[] array containing relevant db-columns
	 */
	protected function getRelevantDbAttributes()
	{
		//get cols from db-schema
		$cols = array_keys($this->owner->getTableSchema()->columns);
		
		//return if no ignored cols
		if (count($this->ignoredAttributes) === 0) return $cols;
		
		//remove ignored cols and return
		$colsFinal = [];
		foreach ($cols as $c) {
			if (in_array($c, $this->ignoredAttributes)) continue;
			$colsFinal[] = $c;
		}
		
		return $colsFinal;
	}
	
	/**
	 * Saves the entry and outputs an exception desccribing the problem if necessary
	 * 
	 * @param \asinfotrack\yii2\audittrail\models\AuditTrailEntry $entry
	 * @throws InvalidValueException if entry couldn't be saved (validation error)
	 */
	protected static function saveEntry($entry)
	{
		//do nothing if successful
		if ($entry->save()) return;
		
		//otherwise throw exception
		$lines = [];
		foreach ($entry->getErrors() as $attr=>$errors) {
			foreach ($errors as $err) $lines[] = $err;
		}
		throw new InvalidValueException(sprintf('Error while saving audit-trail-entry: %s', implode(', ', $lines)));
	}
	
}
