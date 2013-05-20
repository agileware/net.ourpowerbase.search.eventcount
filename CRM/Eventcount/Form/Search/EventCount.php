<?php

/**
 * A custom contact search
 */
class CRM_Eventcount_Form_Search_EventCount extends CRM_Contact_Form_Search_Custom_Base implements CRM_Contact_Form_Search_Interface {
    protected $_formValues;
    protected $_initialized;

    function __construct( &$formValues ) {     
        $this->_formValues = $formValues;

        /**
         * Define the columns for search result rows
         */
        $this->_columns = array( ts('Contact Id')   => 'contact_id'  ,
                                 ts('Name'      )   => 'sort_name',
                                 ts('How many times') => 'participant_count',
                                 ts('Events') => 'event_name' );
    }

    function initialize() {
      if (!$this->_initialized) {
        $initialized = TRUE;
        $this->buildTempTable();
        $this->fillTable();
      }
      $this->_initialized = TRUE;
    }

    function buildTempTable() {
      $randomNum = md5(uniqid());
      $this->_tableName = "civicrm_temp_custom_aec_{$randomNum}";

      $this->_tableFields = array(
        'id' => 'int unsigned NOT NULL AUTO_INCREMENT',
        'contact_id' => 'int unsigned',
        'sort_name' => 'varchar(128)',
        'participant_count' => 'int unsigned',
        'event_name' => 'varchar(4096)',
      );

      $sql = "CREATE TEMPORARY TABLE " . $this->_tableName . " ( ";
      foreach ($this->_tableFields as $name => $desc) {
        $sql .= "$name $desc,\n";
      }

      $sql .= "PRIMARY KEY ( id )) ENGINE=MEMORY DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci";
      CRM_Core_DAO::executeQuery($sql);
    }

    function fillTable() {
      $select  = "DISTINCT NULL, contact_a.id as contact_id, contact_a.sort_name as sort_name,
        COUNT(participant.contact_id) as participant_count, GROUP_CONCAT(DISTINCT(event.title)) 
        as event_name";
      $from  = 'civicrm_participant as participant JOIN civicrm_event as event ON participant.event_id = event.id ' .
        'JOIN civicrm_contact AS contact_a ON contact_a.id = participant.contact_id';
      $where = $this->build_temp_table_where();

      $having = $this->build_temp_table_having();
      if ( $having ) {
        $having = " HAVING $having ";
      }

      $sql = "REPLACE INTO {$this->_tableName} SELECT $select FROM $from WHERE $where GROUP BY contact_a.id $having";
      CRM_Core_DAO::executeQuery($sql);
    }

    function buildForm( &$form ) {
        /**
         * You can define a custom title for the search form
         */
        $this->setTitle('Find People Who Have Attended Events Multiple Times');

        /**
         * Define the search form fields here
         */
        $form->add( 'text',
                    'min_amount',
                    ts( 'At least how many events attended' ) );
        $form->addRule( 'min_amount', ts( 'Please enter a valid amount (numbers and decimal point only).' ), 'money' );

        $form->addDate( 'start_date', ts('Event Date From'), false, array( 'formatType' => 'custom') );
        $form->addDate( 'end_date', ts('...through'), false, array( 'formatType' => 'custom') );

	      $event_type = CRM_Core_OptionGroup::values( 'event_type', false );
        foreach($event_type as $eventId => $eventName) {
            $form->addElement('checkbox', "event_type_ids[$eventId]", 'Event Type', $eventName);
	      }
        /**
         * If you are using the sample template, this array tells the template fields to render
         * for the search form.
         */
        $form->assign( 'elements', array( 'min_amount', 'start_date', 'end_date', 'event_type_ids') );
    }

    /**
     * Define the smarty template used to layout the search form and results listings.
     */
    function templateFile( ) {
       return 'EventCount/Form/Search/EventCount.tpl';
    }
       
    /**
      * Construct the search query
      */       
    function all( $offset = 0, $rowcount = 0, $sort = null,
                  $includeContactIDs = false, $onlyIDs = false ) {

      $this->initialize();

      // SELECT clause must include contact_id as an alias for civicrm_contact.id
      if ( $onlyIDs ) {
        $select  = "temp.contact_id";
      } else {
        $select  = "temp.contact_id, temp.sort_name, temp.participant_count, temp.event_name";
      }
      $from  = $this->from();
      $where = $this->where($includeContactIDs);
      $having = $this->build_temp_table_having();
      if ( $having ) {
          $having = " HAVING $having ";
      }
      $sql = " SELECT $select FROM $from WHERE $where ";
      //for only contact ids ignore order.
      if ( !$onlyIDs ) {
        // Define ORDER BY for query in $sort, with default value
        if (!empty( $sort )) {
          if(is_string($sort)) {
            $sql .= " ORDER BY $sort ";
          } else {
            $sql .= " ORDER BY " . trim( $sort->orderBy() );
          }
        } else {
          $sql .= "ORDER BY participant_count desc";
        }
      }

      if ( $rowcount > 0 && $offset >= 0 ) {
          $sql .= " LIMIT $offset, $rowcount ";
      }
      return $sql;
    }

    function from( ) {
      return $this->_tableName . ' temp INNER JOIN civicrm_contact contact_a ON temp.contact_id = contact_a.id';
    }

     /*
      * This is the WHERE clause that is used when selecting from the temp table.
      * See build_temp_table_where for the where statement used to build the temp table. 
      *
      */
    function where( $includeContactIDs = FALSE ) {
      $clauses = array('1');
      if ($includeContactIDs) {
        $contactIDs = array();
        foreach ( $this->_formValues as $id => $value ) {
          if ( $value &&
            substr( $id, 0, CRM_Core_Form::CB_PREFIX_LEN ) == CRM_Core_Form::CB_PREFIX ) {
            $contactIDs[] = intval(substr( $id, CRM_Core_Form::CB_PREFIX_LEN ));
          }
        }
        if (!empty( $contactIDs ) ) {
          $contactIDs = implode( ', ', $contactIDs );
          $clauses[] = "contact_id IN ( $contactIDs )";
        }
      }
      return implode( ' AND ', $clauses );
    }

    function build_temp_table_where() {
      $clauses = array( );

      $clauses[] = "participant.status_id in ( 2 )";

      $startDate = CRM_Utils_Date::processDate( $this->_formValues['start_date'] );
      if ($startDate) {
        $clauses[] = "event.start_date >= $startDate";
      }

      $endDate = CRM_Utils_Date::processDate( $this->_formValues['end_date'] );
      if ($endDate) {
        $clauses[] = "event.start_date <= $endDate";
      }

      if (!empty( $this->_formValues['event_id'] ) ) {
        $clauses[] = "civicrm_event.id = " . intval($this->_formValues['event_id']);
      }

      if (!empty($this->_formValues['event_type_ids'])) {
        $eids = array();
        // Ensure event ids submitted by user are really integers to avoid xss attacks.
        while(list($k,$v) = each($this->_formValues['event_type_ids'])) {
          $eids[] = intval($k);
        }
        $event_type_ids = implode(',', $eids);
        $clauses[] = "event.event_type_id IN ( $event_type_ids )";
      }

      return implode( ' AND ', $clauses );
    }

    function build_temp_table_having( $includeContactIDs = false ) {
      $clauses = array( );
      $min = CRM_Utils_Array::value( 'min_amount', $this->_formValues);
      if ( $min ) {
        $min = intval($min);
        $clauses[] = "COUNT(participant.contact_id) >= $min";
      }
      return implode(' AND ', $clauses);
    }

    /* 
     * Functions below generally don't need to be modified
     */
    function count( ) {
      $this->initialize();
      $sql = $this->all( );
      $dao = CRM_Core_DAO::executeQuery($sql, CRM_Core_DAO::$_nullArray);
      return $dao->N;
    }
       
    function contactIDs( $offset = 0, $rowcount = 0, $sort = null) { 
      $this->initialize();
      return $this->all( $offset, $rowcount, $sort, false, true );
    }
       
    function &columns( ) {
        return $this->_columns;
    }

   function setTitle( $title ) {
       if ( $title ) {
           CRM_Utils_System::setTitle( $title );
       } else {
           CRM_Utils_System::setTitle(ts('Search'));
       }
   }

   function summary( ) {
       return null;
   }
}
