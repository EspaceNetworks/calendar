<?php
namespace FreePBX\modules;
use \Moment\Moment;
use \Moment\CustomFormats\MomentJs;
use \Ramsey\Uuid\Uuid;
use \Ramsey\Uuid\Exception\UnsatisfiedDependencyException;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use it\thecsea\simple_caldav_client\SimpleCalDAVClient;
use om\IcalParser;

include __DIR__."/vendor/autoload.php";

class Calendar extends \DB_Helper implements \BMO {
	private $now; //right now, private so it doesnt keep updating

	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			throw new Exception("Not given a FreePBX Object");
		}
		$this->now = Carbon::now();
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		$this->systemtz = $this->FreePBX->View()->getTimezone();
		$this->eventDefaults = array(
				'uid' => '',
				'user' => '',
				'description' => '',
				'hookdata' => '',
				'active' => true,
				'generatehint' => false,
				'generatefc' => false,
				'eventtype' => 'calendaronly',
				'weekdays' => '',
				'monthdays' => '',
				'months' => '',
				'timezone' => $this->systemtz,
				'startdate' => '',
				'enddate' => '',
				'starttime' => '',
				'endtime' => '',
				'repeatinterval' => '',
				'frequency' => '',
				'truedest' => '',
				'falsedest' => ''
			);
	}

	public function backup() {}
	public function restore($backup) {}
  public function install(){}
  public function uninstall(){}
	public function doConfigPageInit($page) {
		switch ($page) {
			case 'calendar':
				$action = isset($_REQUEST['action'])?$_REQUEST['action']:'';
				switch($action) {
					case "add":
						if(isset($_POST['name'])) {
							$name = $_POST['name'];
							$description = $_POST['description'];
							$type = $_POST['type'];
							switch($type) {
								case "ical":
									$url = $_POST['url'];
									$this->addRemoteiCalCalendar($name,$description,$url);
								break;
								case "google":
								break;
								case "caldav":
									$purl = $_POST['purl'];
									$surl = $_POST['surl'];
									$username = $_POST['username'];
									$password = $_POST['password'];
									$calendars = $_POST['calendars'];
									$this->addRemoteCalDavCalendar($name,$description,$purl,$surl,$username,$password,$calendars);
								break;
								case "outlook":
								break;
								case "local":
									$this->addLocalCalendar($name,$description);
								break;
							}
						}
					break;
					case "edit":
						if(isset($_POST['name'])) {
							$name = $_POST['name'];
							$description = $_POST['description'];
							$type = $_POST['type'];
							$id = $_POST['id'];
							switch($type) {
								case "ical":
									$url = $_POST['url'];
									$this->updateRemoteiCalCalendar($id,$name,$description,$url);
								break;
								case "google":
								break;
								case "caldav":
									$purl = $_POST['purl'];
									$surl = $_POST['surl'];
									$username = $_POST['username'];
									$password = $_POST['password'];
									$calendars = $_POST['calendars'];
									$this->updateRemoteCalDavCalendar($id,$name,$description,$purl,$surl,$username,$password,$calendars);
								break;
								case "outlook":
								break;
								case "local":
									$this->updateLocalCalendar($id,$name,$description);
								break;
							}
						}
					break;
					case "delete":
						$this->delCalendarByID($_REQUEST['id']);
					break;
				}
			break;
			case 'calendargroups':
				$action = isset($_REQUEST['action'])?$_REQUEST['action']:'';
				$description = isset($_REQUEST['description'])?$_REQUEST['description']:'';
				$events = isset($_REQUEST['events'])?$_REQUEST['events']:array();
				switch($action) {
					case "add":
						if(isset($_POST['name'])) {
							$name = !empty($_POST['name']) ? $_POST['name'] : array();
							$calendars = !empty($_POST['calendars']) ? $_POST['calendars'] : array();
							$categories = !empty($_POST['categories']) ? $_POST['categories'] : array();
							$events = !empty($_POST['events']) ? $_POST['events'] : array();
							$this->addGroup($name,$calendars,$categories,$events);
						}
					break;
					case "edit":
						if(isset($_POST['name'])) {
							$id = $_POST['id'];
							$name = !empty($_POST['name']) ? $_POST['name'] : array();
							$calendars = !empty($_POST['calendars']) ? $_POST['calendars'] : array();
							$categories = !empty($_POST['categories']) ? $_POST['categories'] : array();
							$events = !empty($_POST['events']) ? $_POST['events'] : array();
							$this->updateGroup($id,$name,$calendars,$categories,$events);
						}
					break;
					case "delete":
						$id = $_GET['id'];
						$this->deleteGroup($id);
					break;
				}
			break;
		}
	}
	public function ajaxRequest($req, &$setting) {
		switch($req){
			case 'grid':
			case 'events':
			case 'eventform':
			case 'delevent':
			case 'groupsgrid':
			case 'groupeventshtml':
			case 'getcaldavcals':
				return true;
			break;
			default:
				return false;
			break;
		}
	}
	public function ajaxHandler() {
		switch ($_REQUEST['command']) {
			case 'getcaldavcals':
				$caldavClient = new SimpleCalDAVClient();
				$caldavClient->connect($_POST['purl'], $_POST['username'], $_POST['password']);
				$calendars = $caldavClient->findCalendars();
				$chtml = '';
				foreach($calendars as $calendar) {
					$chtml .= '<option value="'.$calendar->getCalendarID().'">'.$calendar->getDisplayName().'</option>';
				}
				return array("calshtml" => $chtml);
			break;
			case 'groupeventshtml':
				$allCalendars = $this->listCalendars();
				$calendars = !empty($_POST['calendars']) ? $_POST['calendars'] : array();
				$dcategories = !empty($_POST['categories']) ? $_POST['categories'] : array();
				$categories = array();
				foreach($dcategories as $cat) {
					$parts = explode("_",$cat,2);
					$categories[$parts[0]][] = $parts[1];
				}
				$chtml = '';
				foreach($calendars as $calendarID) {
					$cats = $this->getCategoriesByCalendarID($calendarID);
					$chtml .= '<optgroup label="'.$allCalendars[$calendarID]['name'].'">';
					foreach($cats as $name => $events) {
						$chtml .= '<option value="'.$calendarID.'_'.$name.'">'.$name.'</option>';
					}
					$chtml .= '</optgroup>';
				}
				$ehtml = '';
				foreach($calendars as $calendarID) {
					$events = $this->listEvents($calendarID);
					if(!empty($categories[$calendarID])) {
						$valid = array();
						$cats = $this->getCategoriesByCalendarID($calendarID);
						foreach($cats as $category => $evts) {
							if(in_array($category,$categories[$calendarID])) {
								$evts = array_flip($evts);
								$valid = array_merge($valid,$evts);
							}
						}
						$events = array_intersect_key($events,$valid);
					} elseif(!empty($categories)) {
						$events = array();
					}
					$ehtml .= '<optgroup label="'.$allCalendars[$calendarID]['name'].'">';
					foreach($events as $event) {
						$extended = $event['allDay'] ? $event['startdate'] : $event['startdate'].' '._('to').' '.$event['enddate'];
						$ehtml .= '<option value="'.$calendarID.'_'.$event['uid'].'">'.$event['name'].' ('.$extended.')</option>';
					}
					$ehtml .= '</optgroup>';
				}
				return array("eventshtml" => $ehtml, "categorieshtml" => $chtml);
			break;
			case 'delevent':
				$calendarID = $_POST['calendarid'];
				$eventID = $_POST['eventid'];
				$this->deleteEvent($calendarID,$eventID);
			break;
			case 'grid':
				$calendars = $this->listCalendars();
				$final = array();
				foreach($calendars as $id => $data) {
					$data['id'] = $id;
					$final[] = $data;
				}
				return $final;
			break;
			case 'events':
				$start = new Carbon($_GET['start'],$_GET['timezone']);
				$end = new Carbon($_GET['end'],$_GET['timezone']);
				$events = $this->listEvents($_REQUEST['calendarid'],$start, $end);
				$events = is_array($events) ? $events : array();
				return array_values($events);
			break;
			case 'eventform':
				$date = new Carbon($_POST['startdate']." ".$_POST['starttime'], $this->systemtz);
				$starttime = $date->format('U');

				$date = new Carbon($_POST['enddate']." ".$_POST['endtime'], $this->systemtz);
				$endtime = $date->format('U');

				$name = $_POST['description'];
				$calendarID = $_POST['calendarid'];
				$description = $_POST['description'];
				if(isset($_REQUEST['eventid']) && $_REQUEST['eventid'] == 'new'){
					$this->addEvent($calendarID,null,$name,$description,$starttime,$endtime,$this->systemtz);
				}else{
					$this->updateEvent($calendarID,$_REQUEST['eventid'],$name,$description,$starttime,$endtime,$this->systemtz);
				}
			break;
			case 'groupsgrid':
				$groups =  $this->listGroups();
				$final = array();
				foreach($groups as $id => $data) {
					$data['id'] = $id;
					$final[] = $data;
				}
				return $final;
			break;
    }
  }

	public function showCalendarGroupsPage() {
		$action = !empty($_GET['action']) ? $_GET['action'] : '';
		switch($action) {
			case "add":
				$calendars = $this->listCalendars();
				return load_view(__DIR__."/views/calendargroups.php",array("calendars" => $calendars, "action" => _("Add")));
			break;
			case "edit":
				$calendars = $this->listCalendars();
				$group = $this->getGroup($_REQUEST['id']);
				return load_view(__DIR__."/views/calendargroups.php",array("calendars" => $calendars, "group" => $group, "action" => _("Edit")));
			break;
			case "view":
			break;
			default:
				return load_view(__DIR__."/views/calendargroupgrid.php",array());
			break;
		}
	}

	public function showCalendarPage() {
		$action = !empty($_GET['action']) ? $_GET['action'] : '';
		switch($action) {
			case "add":
				$type = !empty($_GET['type']) ? $_GET['type'] : '';
				switch($type) {
					case "ical":
						return load_view(__DIR__."/views/remote_ical_settings.php",array('action' => 'add', 'type' => $type));
					break;
					case "caldav":
						return load_view(__DIR__."/views/remote_caldav_settings.php",array('action' => 'add', 'type' => $type));
					break;
					case "outlook":
					case "google":
					break;
					case "local":
						return load_view(__DIR__."/views/local_settings.php",array('action' => 'add', 'type' => $type));
					break;
				}
			break;
			case "edit":
				$data = $this->getCalendarByID($_GET['id']);
				switch($data['type']) {
					case "ical":
						return load_view(__DIR__."/views/remote_ical_settings.php",array('action' => 'edit', 'type' => $data['type'], 'data' => $data));
					break;
					case "caldav":
						$caldavClient = new SimpleCalDAVClient();
						$caldavClient->connect($data['purl'], $data['username'], $data['password']);
						$cals = $caldavClient->findCalendars();
						$calendars = array();
						foreach($cals as $calendar) {
							$id = $calendar->getCalendarID();
							$calendars[$id] = array(
								"id" => $id,
								"name" => $calendar->getDisplayName(),
								"selected" => in_array($id,$data['calendars'])
							);
						}
						return load_view(__DIR__."/views/remote_caldav_settings.php",array('action' => 'edit', 'type' => $data['type'], 'data' => $data, 'calendars' => $calendars));
					break;
					case "outlook":
					case "google":
					break;
					case "local":
						return load_view(__DIR__."/views/local_settings.php",array('action' => 'edit', 'type' => $data['type'], 'data' => $data));
					break;
				}
			break;
			case "view":
				$data = $this->getCalendarByID($_GET['id']);
				return load_view(__DIR__."/views/calendar.php",array('action' => 'view', 'type' => $data['type'], 'data' => $data));
			break;
			default:
				return load_view(__DIR__."/views/grid.php",array());
			break;
		}
	}

	Public function myDialplanHooks(){
		return '490';
	}

	public function doDialplanHook(&$ext, $engine, $priority){
		//Dialplan
		$dpapp = 'calendar-groups';
		$ext->addInclude('from-internal-additional',$dpapp);
		foreach ($this->listGroups() as $key => $value) {
			$ext->add($dpapp,900,'', $this->ext_calendar_group_goto("41f0392b-bfc1-43a0-b5e7-0fb00935343b","ext-local,1,1","ext-local,1,2"));
		}
	}

	/**
	 * Get Event by Event ID
	 * @param  string $calendarID The calendar ID
	 * @param  string $id The event ID
	 * @return array     The returned event array
	 */
	public function getEvent($calendarID,$eventID) {
		$events = $this->getAll($calendarID.'-events');
		return isset($events[$eventID]) ? $events[$eventID] : false;
	}

	/**
	 * List Calendars
	 * @return array The returned calendar array
	 */
	public function listCalendars() {
		$calendars = $this->getAll('calendars');
		return $calendars;
	}

	/**
	 * Delete Calendar by ID
	 * @param  string $id The calendar ID
	 */
	public function delCalendarByID($id) {
		$this->setConfig($id,false,'calendars');
		$this->delById($id."-events");
		$this->delById($id."-linked-events");
		$this->delById($id."-categories-events");
	}

	/**
	 * Get Calendar by ID
	 * @param  string $id The Calendar ID
	 * @return array     Calendar data
	 */
	public function getCalendarByID($id) {
		$final = $this->getConfig($id,'calendars');
		$final['id'] = $id;
		return $final;
	}

	/**
	 * List Events
	 * @param  string $calendarID The calendarID to reference
	 * @param  object $start  Carbon Object
	 * @param  object $stop   Carbon Object
	 * @param  bool $subevents Break date ranges in to daily events.
	 * @return array  an array of events
	 */
	public function listEvents($calendarID, $start = null, $stop = null, $subevents = false) {
		$return = array();
		$events = $this->getAll($calendarID.'-events');

		if(!empty($start) && !empty($stop)){
			$events = $this->eventFilterDates($events, $start, $stop);
		}

		foreach($events as $uid => $event){
			$starttime = !empty($event['starttime'])?$event['starttime']:'00:00:00';
			$endtime = !empty($event['endtime'])?$event['endtime']:'23:59:59';
			$event['ustarttime'] = $event['starttime'];
			$event['uendtime'] = $event['endtime'];
			$event['title'] = $event['name'];
			$event['uid'] = $uid;
			if(($event['starttime'] != $event['endtime']) && $subevents) {
				$startrange = Carbon::createFromTimeStamp($event['starttime'],$this->systemtz);
				$endrange = Carbon::createFromTimeStamp($event['endtime'],$this->systemtz);
				$daterange = new \DatePeriod($startrange, CarbonInterval::day(), $endrange);
				$i = 0;
				foreach($daterange as $d) {
					$tempevent = $event;
					$tempevent['uid'] = $uid.'_'.$i;
					$tempevent['ustarttime'] = $event['starttime'];
					$tempevent['uendtime'] = $event['endtime'];
					$tempevent['startdate'] = $d->format('Y-m-d');
					$tempevent['enddate'] = $d->format('Y-m-d');
					$tempevent['starttime'] = $d->format('H:i:s');
					$tempevent['endtime'] = $d->format('H:i:s');
					$tempevent['start'] = sprintf('%sT%s',$tempevent['startdate'],$tempevent['starttime']);
					$tempevent['end'] = sprintf('%sT%s',$tempevent['enddate'],$tempevent['endtime']);
					$tempevent['allDay'] = ($event['endtime'] - $event['starttime']) === 86400;
					//$tempevent['now'] = $this->now->between($start, $end);
					$tempevent['parent'] = $event;
					$return[$tempevent['uid']] = $tempevent;
					$i++;
				}
			}else{
				$event['ustarttime'] = $event['starttime'];
				$event['uendtime'] = $event['endtime'];

				$start = Carbon::createFromTimeStamp($event['ustarttime'],$this->systemtz);
				if($event['starttime'] == $event['endtime']) {
					$event['allDay'] = true;
					$end = $start->copy()->addDay();
				} else {
					$event['allDay'] = ($event['endtime'] - $event['starttime']) === 86400;
					$end = Carbon::createFromTimeStamp($event['uendtime'],$this->systemtz);
				}

				$event['uid'] = $uid;
				$event['startdate'] = $start->format('Y-m-d');
				$event['enddate'] = $end->format('Y-m-d');
				$event['starttime'] = $start->format('H:i:s');
				$event['endtime'] = $end->format('H:i:s');
				$event['start'] = sprintf('%sT%s',$event['startdate'],$event['starttime']);
				$event['end'] = sprintf('%sT%s',$event['enddate'],$event['endtime']);
				$event['now'] = $this->now->between($start, $end);

				$return[$uid] = $event;
			}
		}
		uasort($return, function($a, $b) {
			if ($a['ustarttime'] == $b['ustarttime']) {
				return 0;
			}
			return ($a['ustarttime'] < $b['ustarttime']) ? -1 : 1;
		});
		return $return;
	}

	/**
	 * Filter Event Dates
	 * @param  array $data  Array of Events
	 * @param  object $start  Carbon Object
	 * @param  object $stop   Carbon Object
	 * @return array  an array of events
	 */
	public function eventFilterDates($data, $start, $end){
		$final = $data;
		foreach ($data as $key => $value) {
			if(!isset($value['starttime']) || !isset($value['endtime'])){
				unset($data[$key]);
				continue;
			}
			$timezone = isset($value['timezone'])?$value['timezone']:$this->systemtz;
			$startdate = Carbon::createFromTimeStamp($value['starttime'],$timezone);
			$enddate = Carbon::createFromTimeStamp($value['endtime'],$timezone);

			if($start->between($startdate,$enddate) || $end->between($startdate,$enddate)) {
				continue;
			}

			if($startdate->between($start,$end) || $enddate->between($start,$end)) {
				continue;
			}

			$daysLong = $startdate->diffInDays($enddate);
			if($daysLong > 0) {
				$daterange = new \DatePeriod($startdate, CarbonInterval::day(), $enddate);
				foreach($daterange as $d) {
					if($d->between($start,$end)) {
						continue(2);
					}
				}
			}
			unset($final[$key]);
		}
		return $final;
	}

	/**
	 * Add Event to specific calendar
	 * @param string $calendarID  The Calendar ID
	 * @param string $eventID     The Event ID, if null will auto generatefc
	 * @param string $name        The event name
	 * @param string $description The event description
	 * @param string $starttime   The event start timezone
	 * @param string $endtime     The event end time
	 * @param boolean $recurring  Is this a recurring event
	 * @param string $linkedID    The master ID if the event is recurring
	 * @param array $categories   The categories assigned to this event
	 */
	public function addEvent($calendarID,$eventID=null,$name,$description,$starttime,$endtime,$timezone=null,$recurring=false,$linkedID=null,$categories=array()){
		$uuid = !is_null($eventID) ? $eventID : Uuid::uuid4();
		$this->updateEvent($calendarID,$eventID,$name,$description,$starttime,$endtime,$timezone,$recurring,$linkedID,$categories);
	}

	/**
	 * Update Event on specific calendar
	 * @param string $calendarID  The Calendar ID
	 * @param string $eventID     The Event ID, if null will auto generatefc
	 * @param string $name        The event name
	 * @param string $description The event description
	 * @param string $starttime   The event start timezone
	 * @param string $endtime     The event end time
	 * @param boolean $recurring  Is this a recurring event
	 * @param string $linkedID    The master ID if the event is recurring
	 * @param array $categories   The categories assigned to this event
	 */
	public function updateEvent($calendarID,$eventID,$name,$description,$starttime,$endtime,$timezone=null,$recurring=false,$linkedID=null,$categories=array()) {
		if(!isset($eventID) || is_null($eventID) || trim($eventID) == "") {
			throw new \Exception("Event ID can not be blank");
		}
		//TODO: Store timezone? We get it....
		$event = array(
			"name" => $name,
			"description" => $description,
			"starttime" => $starttime,
			"endtime" => $endtime,
			"recurring" => $recurring,
			"linkedid" => $linkedID,
			"categories" => $categories
		);
		$this->setConfig($eventID,$event,$calendarID."-events");

		$linkedID = !empty($linkedID) ? $linkedID : $eventID;
		$linked = $this->getConfig($linkedID,$calendarID."-linked-events");
		if(empty($linked)) {
			$linked = array(
				$eventID
			);
		} elseif(!in_array($linkedID,$linked)) {
			$linked[] = $eventID;
		}
		$this->setConfig($linkedID,$linked,$calendarID."-linked-events");

		foreach($categories as $category) {
			$events = $this->getConfig($category,$calendarID."-categories-events");
			if(empty($events)) {
				$events = array(
					$eventID
				);
			} elseif(!in_array($eventID,$events)) {
				$events[] = $eventID;
			}
			$this->setConfig($category,$events,$calendarID."-categories-events");
		}
	}

	/**
	 * Delete event from specific calendar
	 * @param  string $calendarID The Calendar ID
	 * @param  string $eventID    The event ID
	 */
	public function deleteEvent($calendarID,$eventID) {
		$this->setConfig($eventID,false,$calendarID."-events");
	}

	public function addRemoteCalDavCalendar($name,$description,$purl,$surl,$username,$password,$calendars) {
		$uuid = Uuid::uuid4();
		$this->updateRemoteCalDavCalendar($uuid,$name,$description,$purl,$surl,$username,$password,$calendars);
	}

	public function updateRemoteCalDavCalendar($id,$name,$description,$purl,$surl,$username,$password,$calendars) {
		if(empty($id)) {
			throw new \Exception("Calendar ID is empty");
		}
		$calendar = array(
			"name" => $name,
			"description" => $description,
			"type" => "caldav",
			"purl" => $purl,
			"surl" => $surl,
			"username" => $username,
			"password" => $password,
			"calendars" => $calendars
		);
		$this->setConfig($id,$calendar,'calendars');
		$calendar['id'] = $id;
		$this->processCalendar($calendar);
	}

	/**
	 * Add a Remote Calendar
	 * @param string $name        The Calendar name
	 * @param string $description The Calendar description
	 * @param string $type        The Calendar type
	 * @param string $url         The Calendar URL
	 */
	public function addRemoteiCalCalendar($name,$description,$url) {
		$uuid = Uuid::uuid4();
		$this->updateRemoteiCalCalendar($uuid,$name,$description,$url);
	}

	/**
	 * Add Local Calendar
	 * @param string $name        The Calendar name
	 * @param string $description The Calendar description
	 */
	public function addLocalCalendar($name,$description) {
		$uuid = Uuid::uuid4();
		$this->updateLocalCalendar($uuid,$name,$description);
	}

	public function sync() {
		$calendars = $this->listCalendars();
		foreach($calendars as $id => $calendar) {
			if($calendar['type'] !== "local") {
				$calendar['id'] = $id;
				$this->processCalendar($calendar);
			}
		}
	}

	/**
	 * Update a Remote Calendar's settings
	 * @param string $id          The Calendar ID
	 * @param string $name        The Calendar name
	 * @param string $description The Calendar description
	 * @param string $type        The Calendar type
	 * @param string $url         The Calendar URL
	 */
	public function updateRemoteiCalCalendar($id,$name,$description,$url) {
		if(empty($id)) {
			throw new \Exception("Calendar ID is empty");
		}
		$calendar = array(
			"name" => $name,
			"description" => $description,
			"type" => "ical",
			"url" => $url
		);
		$this->setConfig($id,$calendar,'calendars');
		$calendar['id'] = $id;
		$this->processCalendar($calendar);
	}

	/**
	 * Update a Remote Calendar's settings
	 * @param string $id          The Calendar ID
	 * @param string $name        The Calendar name
	 * @param string $description The Calendar description
	 */
	public function updateLocalCalendar($id,$name,$description) {
		if(empty($id)) {
			throw new \Exception("Calendar ID is empty");
		}
		$calendar = array(
			"name" => $name,
			"description" => $description,
			"type" => 'local'
		);
		$this->setConfig($id,$calendar,'calendars');
		$calendar['id'] = $id;
	}

	/**
	 * Process remote calendar actions
	 * @param  array $calendar Calendar information (From getCalendarByID)
	 */
	public function processCalendar($calendar) {
		if(empty($calendar['id'])) {
			throw new \Exception("Calendar ID can not be empty!");
		}

		$this->db->beginTransaction();

		$this->delById($calendar['id']."-events");
		$this->delById($calendar['id']."-linked-events");
		$this->delById($calendar['id']."-categories-events");

		switch($calendar['type']) {
			case "caldav":
				$caldavClient = new SimpleCalDAVClient();
				$caldavClient->connect($calendar['purl'], $calendar['username'], $calendar['password']);
				$cals = $caldavClient->findCalendars();
				foreach($calendar['calendars'] as $c) {
					if(isset($cals[$c])) {
						$caldavClient->setCalendar($cals[$c]);
						$events = $caldavClient->getEvents();
						foreach($events as $event) {
							$ical = $event->getData();
							$cal = new IcalParser();
							$cal->parseString($ical);
							$this->processiCalEvents($calendar['id'], $cal);
						}
					}
				}
			break;
			case "ical":
				$cal = new IcalParser();
				$cal->parseFile($calendar['url']);
				$this->processiCalEvents($calendar['id'], $cal);
		break;
		}
		$this->db->commit();
	}

	/**
	 * Process iCal Type events
	 * @param  string     $calendarID The Calendar ID
	 * @param  IcalParser $cal        IcalParser Object reference of events
	 */
	private function processiCalEvents($calendarID, IcalParser $cal) {
		foreach ($cal->getSortedEvents() as $event) {
			if($event['DTSTART']->format('U') == 0) {
				continue;
			}

			$event['UID'] = isset($event['UID']) ? $event['UID'] : 0;
			$linkedID = $event['UID'];
			if($event['RECURRING'] && !isset($uuids[$event['UID']])) {
				$uuids[$event['UID']] = 0;
				$event['UID'] = $event['UID']."_0";
			} elseif($event['RECURRING'] && isset($uuids[$event['UID']])) {
				$uuids[$event['UID']]++;
				$event['UID'] = $event['UID']."_".$uuids[$event['UID']];
			}

			$recurring = !empty($event['RECURRING']) ? true : false;

			$categories = is_array($event['CATEGORIES']) ? $event['CATEGORIES'] : array();

			$event['DESCRIPTION'] = !empty($event['DESCRIPTION']) ? $event['DESCRIPTION'] : "";

			if($event['DTSTART']->getTimezone() != $event['DTEND']->getTimezone()) {
				throw new \Exception("Start timezone and end timezone are different! Not sure what to do here");
			}
			$tz = $event['DTSTART']->getTimezone();
			$timezone = $tz->getName();
			$this->updateEvent($calendarID,$event['UID'],htmlspecialchars_decode($event['SUMMARY'], ENT_QUOTES),htmlspecialchars_decode($event['DESCRIPTION'], ENT_QUOTES),$event['DTSTART']->format('U'),$event['DTEND']->format('U'),$timezone,$recurring,$linkedID,$categories);
		}
	}

	/**
	 * Get all the Categories by Calendar ID
	 * @param  string $calendarID The Calendar ID
	 * @return array             Array of Categories with their respective events
	 */
	public function getCategoriesByCalendarID($calendarID) {
		$categories = $this->getAll($calendarID."-categories-events");
		return $categories;
	}

	/**
	 * Add Event Group
	 * @param string $description   The Event Group name
	 * @param array $events The event group events
	 */
	public function addGroup($name,$calendars,$categories,$events) {
		$uuid = Uuid::uuid4();
		$this->updateGroup($uuid,$name,$calendars,$categories,$events);
	}

	/**
	 * Update Event Group
	 * @param string $id The event group id
	 * @param string $description   The Event Group name
	 * @param array $events The event group events
	 */
	public function updateGroup($id,$name,$calendars,$categories,$events) {
		if(empty($id)) {
			throw new \Exception("Event ID can not be blank");
		}
		$event = array(
			"name" => $name,
			"calendars" => $calendars,
			"categories" => $categories,
			"events" => $events
		);
		$this->setConfig($id,$event,"groups");
	}

	/**
	 * Delete Event Group
	 * @param  string $id The event group id
	 */
	public function deleteGroup($id){
		$this->setConfig($id, false, 'groups');
	}

	/**
	 * Get an Event Group by ID
	 * @param  string $id The event group id
	 * @return array     Event Group array
	 */
	public function getGroup($id){
		$grp = $this->getConfig($id,'groups');
		$grp['id'] = $id;
		return $grp;
	}

	/**
	 * List all Event Groups
	 * @return array Even Groups
	 */
	public function listGroups(){
			return $this->getAll('groups');
	}

	/**
	 * Dial Plan Function
	 */
	public function ext_calendar_group_variable($groupid,$integer=false) {
		$group = $this->getGroup($groupid);
		if(empty($group)) {
			throw new \Exception("Group $groupid does not exist!");
		}
		$type = $integer ? 'integer' : 'boolean';
		return new \ext_agi('calendar.agi,group,'.$type.','.$groupid);
	}

	/**
	 * Dial Plan Function
	 */
	public function ext_calendar_group_goto($groupid,$true_dest,$false_dest) {
		$group = $this->getGroup($groupid);
		if(empty($group)) {
			throw new \Exception("Group $groupid does not exist!");
		}
		return new \ext_agi('calendar.agi,group,goto,'.$groupid.','.base64_encode($true_dest).','.base64_encode($false_dest));
	}

	/**
	 * Dial Plan Function
	 */
	public function ext_calendar_group_execif($groupid,$true,$false) {
		$group = $this->getGroup($groupid);
		if(empty($group)) {
			throw new \Exception("Group $groupid does not exist!");
		}
		return new \ext_agi('calendar.agi,group,execif,'.$groupid.','.base64_encode($true).','.base64_encode($true));
	}

	public function matchCategory($calendarID,$category) {

	}

	/**
	 * Checks if any event in said calendar matches the current time
	 * @param  string $calendarID The Calendar ID
	 * @return boolean          True if match, False if no match
	 */
	public function matchCalendar($calendarID) {
		//move back 1 min and forward 1 min to extend our search
		//TODO: Check full hour?
		$start = $this->now->copy()->subMinute();
		$stop = $this->now->copy()->addMinute();
		$events = $this->listEvents($calendarID, $start, $stop);
		foreach($events as $event) {
			if($event['now']) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks if a specific event in a calendar matches the current time
	 * @param  string $calendarID The Calendar ID
	 * @param  string $eventID    The Event ID
	 * @return boolean          True if match, False if no match
	 */
	public function matchEvent($calendarID,$eventID) {
		$event = $this->getEvent($calendarID,$eventID);
		$start = Carbon::createFromTimeStamp($event['starttime'],$this->systemtz);
		$end = Carbon::createFromTimeStamp($event['endtime'],$this->systemtz);
		return $this->now->between($start,$end);
	}

	/**
	 * Checks if the Group Matches the current time
	 * @param  string $groupID The Group ID
	 * @return boolean          True if match, False if no match
	 */
	public function matchGroup($groupID) {
		//move back 1 min and forward 1 min to extend our search
		//TODO: Check full hour?
		$start = $this->now->copy()->subMinute();
		$stop = $this->now->copy()->addMinute();
		//1 query for each calendar instead of 1 query for each event
		$calendars = $this->listCalendars();
		$group = $this->getGroup($groupID);
		if(empty($group)) {
			return false;
		}
		$events = array();
		foreach($calendars as $cid => $calendar) {
			$events = $this->listEvents($cid, $start, $stop);
			if(!empty($group['events'])) {
				foreach($group['events'] as $eventid) {
					$parts = explode("_",$eventid,2);
					$eid = $parts[1]; //eventid is second part, calendarid is first
					if(isset($events[$eid]) && $events[$eid]['now']) {
						return true;
					}
				}
			}
			if(!empty($data['categories'])) {
			}
			if(!empty($data['calendars'])) {
			}
		}
		return false;
	}

	public function getActionBar($request) {
		$buttons = array();
		switch($request['display']) {
			case 'calendar':
				$action = !empty($_GET['action']) ? $_GET['action'] : '';
				switch($action) {
					case "add":
						$buttons = array(
							'reset' => array(
								'name' => 'reset',
								'id' => 'reset',
								'value' => _('Reset')
							),
							'submit' => array(
								'name' => 'submit',
								'id' => 'submit',
								'value' => _('Submit')
							)
						);
					break;
					case "edit":
						$buttons = array(
							'delete' => array(
								'name' => 'delete',
								'id' => 'delete',
								'value' => _('Delete')
							),
							'reset' => array(
								'name' => 'reset',
								'id' => 'reset',
								'value' => _('Reset')
							),
							'submit' => array(
								'name' => 'submit',
								'id' => 'submit',
								'value' => _('Submit')
							)
						);
					break;
				}
			break;
			case 'calendargroups':
			$action = !empty($_GET['action']) ? $_GET['action'] : '';
			switch($action) {
				case "add":
					$buttons = array(
						'reset' => array(
							'name' => 'reset',
							'id' => 'reset',
							'value' => _('Reset')
						),
						'submit' => array(
							'name' => 'submit',
							'id' => 'submit',
							'value' => _('Submit')
						)
					);
				break;
				case "edit":
					$buttons = array(
						'delete' => array(
							'name' => 'delete',
							'id' => 'delete',
							'value' => _('Delete')
						),
						'reset' => array(
							'name' => 'reset',
							'id' => 'reset',
							'value' => _('Reset')
						),
						'submit' => array(
							'name' => 'submit',
							'id' => 'submit',
							'value' => _('Submit')
						)
					);
				break;
			}
			break;
		}
		return $buttons;
	}

	public function getRightNav($request) {
		$request['action'] = !empty($request['action']) ? $request['action'] : '';
		switch($request['action']) {
			case "add":
			case "edit":
			case "view":
				return load_view(__DIR__."/views/rnav.php",array());
			break;
		}
	}

	//UCP STUFF
	public function ucpConfigPage($mode, $user, $action) {
		if(empty($user)) {
			$enabled = ($mode == 'group') ? true : null;
		} else {
			if($mode == 'group') {
				$enabled = $this->FreePBX->Ucp->getSettingByGID($user['id'],'Calendar','enabled');
				$enabled = !($enabled) ? false : true;
			} else {
				$enabled = $this->FreePBX->Ucp->getSettingByID($user['id'],'Calendar','enabled');
			}
		}

		$html = array();
		$html[0] = array(
			"title" => _("Calendar"),
			"rawname" => "calendar",
			"content" => load_view(dirname(__FILE__)."/views/ucp_config.php",array("mode" => $mode, "enabled" => $enabled))
		);
		return $html;
	}
	public function ucpAddUser($id, $display, $ucpStatus, $data) {
		$this->ucpUpdateUser($id, $display, $ucpStatus, $data);
	}
	public function ucpUpdateUser($id, $display, $ucpStatus, $data) {
		if($display == 'userman' && isset($_POST['type']) && $_POST['type'] == 'user') {
			if(isset($_POST['calendar_enable']) && $_POST['calendar_enable'] == 'yes') {
				$this->FreePBX->Ucp->setSettingByID($id,'Calendar','enabled',true);
			}elseif(isset($_POST['calendar_enable']) && $_POST['calendar_enable'] == 'no') {
				$this->FreePBX->Ucp->setSettingByID($id,'Calendar','enabled',false);
			} elseif(isset($_POST['calendar_enable']) && $_POST['calendar_enable'] == 'inherit') {
				$this->FreePBX->Ucp->setSettingByID($id,'Calendar','enabled',null);
			}
		}
	}
	public function ucpDelUser($id, $display, $ucpStatus, $data) {}
	public function ucpAddGroup($id, $display, $data) {
		$this->ucpUpdateGroup($id,$display,$data);
	}
	public function ucpUpdateGroup($id,$display,$data) {
		if($display == 'userman' && isset($_POST['type']) && $_POST['type'] == 'group') {
			if(isset($_POST['calendar_enable']) && $_POST['calendar_enable'] == 'yes') {
				$this->FreePBX->Ucp->setSettingByGID($id,'Calendar','enabled',true);
			} else {
				$this->FreePBX->Ucp->setSettingByGID($id,'Calendar','enabled',false);
			}
		}
	}
	public function ucpDelGroup($id,$display,$data) {

	}
}
