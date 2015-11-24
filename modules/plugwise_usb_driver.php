<?php

include ("php_serial.class.php");

$driver=new plugwise_usb("/dev/ttyUSB0");

/**********************************************************************
/*		Plugwise USB driver class definitiuon
***********************************************************************/	
class plugwise_usb
{

    private $_processname="plugwise";
    private $_port="/dev/ttyUSB0";
    private $_maxmsg=6;
    private $_maxmsgwait=7;
    private $_maxqueue;
    private $_queue	;
    private $_sysload;	
    private $_pollPower=60;
    private $_pollInfo=60;
    private $_pollClock=86400;
    
    private $_HF_="\x05\x05\x03\x03";
    private $_EF_="\x0d\x0a";

    private $_stickmac=null;
    private $_mcmac=null;
    private $_serial=null;

    private $_msgfeed=array();
    private $_orderfeed=array();
    private $_eventfeed=array();
    private $_devices=array();
    
    private $_device_tpl=array(
            "type"=>"Plugwise(Circle)"
           ,"pulsesInterval1"=>0
           ,"pulsesInterval8"=>0
           ,"pulsesTotal"=>0
           ,"gainA"=>0
           ,"gainB"=>0
           ,"offTot"=>0
           ,"offRuis"=>0
           ,"kwh"=>0
           ,"infocontact"=>null
           ,"inforequest"=>null
           ,"powercontact"=>null
           ,"powerrequest"=>null
           ,"clockcontact"=>null
           ,"clockrequest"=>null 
           ,"year"=>0
           ,"month"=>0
           ,"day"=>0
           ,"hours"=>0
           ,"minutes"=>0
           ,"currentlog"=>0
           ,"state"=>0
           ,"herz"=>0
           ,"hardware"=>0
           ,"firmware"=>0
           ,"power"=>0						
           );

    private $_process_version=null;
    private $_process=null;
    private $_socket=null;
    private $_psocket=null;
    
    private $weekdays=array(
        1=>"Monday",
        2=>"Tuesday",
        3=>"Wednesday",
        4=>"Thursday",
        5=>"Friday",
        6=>"Saturday",
        7=>"Dimanche"
    );

    const ON				="01";
    const OFF				="00";

    const SENT				="0000";
    const RECEIVED			="9999";
    const ACK				="00C1";
    const NACK				="00E1";

    const ACKMSG			="0000";
    const INIT				="000A";
    const POWERINFO			="0012";
    const CLOCKSET			="0016";
    const POWERSTATE			="0017";
    const DISCOVERY			="0018";
    const DEVICEINFO			="0023";
    const CALIBRATION			="0026";
    const CLOCKGET			="003E";
    const BUFFERINFO			="0048";

    const INIT_ANSWER			="0011";
    const POWERINFO_ANSWER		="0013";
    const DISCOVERY_ANSWER		="0019";
    const DEVICEINFO_ANSWER		="0024";
    const CALIBRATION_ANSWER		="0027";
    const CLOCKGET_ANSWER		="003F";
    const BUFFERINFO_ANSWER		="0049";


    /*****************************************************************************
    -------------------------------
      Main Process
    -------------------------------
    This process init the connection to USB stick 
    and start discovering plugwise network

    Then loop :
    -Read order from main program and 
     * answer immediately using in memory information 
     * put other request order in order list for later processing below
    -Run Maintenance Task of the Plugwise network : 
     * put order like get info, get power, set time, search for new device in order list
    -Clean the message queue (delete imessages too old)
    -Sort order by priority and send order to msgQueue based on priority and maxmsg
    -Read answer from the Plugwise Network and delete processed messages from msgQueue
    -Process Plugwise network answer and send back to main program

    *****************************************************************************/	

    public function __construct($port) {

        global $process, $TERMINATE;

        $this->_port=$port;
        $this->_process=$process[$this->_processname]["bootstrap"];
        $this->_process_version=$process[$this->_processname]["version"];

        writelog ("Module ".$this->_processname." version ".$this->_process_version." is running...");

        $this->_socket = $process [$this->_processname]["socket"][0];
        $this->_psocket = $process [$this->_processname]["socket"][1];
        //close parent socket
        socket_close($process [$this->_processname]["socket"][1]);
        
        $this->serial = new phpSerial();

        //configuration of serial port
        $this->serial->deviceSet($this->_port);
        //$serial->confBaudRate(115200); //Baud rate: 115200
        //$serial->confParity("none");  //Parity (this is the "N" in "8-N-1")
        //$serial->confCharacterLength(8); //Character length     (this is the "8" in "8-N-1")
        //$serial->confStopBits(1);  //Stop bits (this is the "1" in "8-N-1")
        //$serial->confFlowControl("none");

        // Opening Serial port
        writelog ($this->_process.": Opening ".$this->_port);
        $this->serial->deviceOpen();

        // Send INIT to get Stick MAC
        $this->_requestInit();


        /*------------------------
                 Main Loop
        ------------------------*/
        while(!$TERMINATE)
        {
                $this->_orderReceive() ;
                $this->_taskUpdate();
                $this->_msgCleanQueue();
                $this->_orders();      
                $feed = $this->_msgRead();
                $this->_msgProcess($feed)	;
                usleep(200000);
        }       
        // Finishing Process
        writelog ("Process ".$this->_process." received terminate request");

        // Closing serial port
        writelog ($this->_process.": Closing ".$this->_port);
        $this->serial->deviceClose();

        //closing Socket
        writelog ($this->_process.": Closing socket ".$this->_socket);
        socket_close($this->_socket);
        //leaving
        writelog ($this->_process." : Dave ...I can feel it. My mind is going.");
     }

    /*=====================================================
                        Orders managment
     * Order are receviced through IPC socket 
     * from main Program
    =======================================================*/	

    public function _orderReceive() 
    /******************************************************
     _orderReceive : 
     Answer order received from parent socket IPC
     function read all order receive and answer to them immediately
     this function is call at each main loop
     so it should never be stopped by a process
    ******************************************************/
    {

        //check if pending order on IPC socket
        $read=array($this->_socket);

        if (socket_select($read, $write = NULL, $except = NULL, 0) < 1) {

            //echo "Nothing to read\n";

        } else {

            //echo "Reading order\n";
            $socketorders = socket_read($this->_socket, 1024, PHP_NORMAL_READ);
            //echo "finish Reading order\n";

            // There is no data
            if($socketorders === false) {

                $error = socket_last_error($this->_socket);
                if($error != 11 && $error != 115) {
                                //my_error_handler(socket_strerror($error), $error);
                }

            // There is data
            }else {

                //split each line in an $command array			     
                $command=preg_split('/\n|\r\n?/', $socketorders,PREG_SPLIT_NO_EMPTY);

                //convert all orders to lower case
                array_walk($command, function(&$value)
                        {
                                $value = trim(strtolower($value));
                        });

                //proceed each order in $command array
                foreach($command as $order) {

                    //order is not empty						
                    if ($order<>'') {

                        switch ($order) {

                        case "get device list":
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this->_answerGetDeviceList();
                            break;

                        case "get device count":
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this->_answerGetDeviceCount();
                            break;

                        case "get device power":
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this-> _answerGetDevicePower();
                            break;

                        case "get message list":
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this->_answerGetMsgList();
                            break;

                        case "get message count":
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this->_answerGetMsgCount();
                            break;

                        case "get order list":
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this->_answerGetOrderList();
                            break;

                        case "get order count":
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this->_answerGetOrderCount();
                            break;

                        case "request discovery":
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this->_answerRequestDiscovery();
                            break;

                        case "request init":
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this->_answerRequestInit();
                            break;

                        case "request info update":
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this->_answerRequestUpdateInfo();
                            break;

                        case "request power update":
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this->_answerRequestUpdatePower();
                            break;

                        case (preg_match('#^set device [a-z0-9]{16} on|off#', $order) ? true : false):
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this->_answerSetDeviceONOFF($order);
                            break;

                        default:
                            writelog ($this->_process." : Order Receive : ".$order);
                            $this->_answerUnknown();
                            break;
                        }

                    //send empty order from parent : like plugwise with no argument
                    } else {
                            $this->_answerHelp();
                    }

                }  //end of for each order

            } // socket read send ture but there is no data to read (empty)

        } //nothing in IPC socket is waiting
    }

    public function _orders() 
    /******************************************************
     _orders
     This function manage the orderfeed queue
     * update Queue Statistic
     * sort order by priority
     * send realtime order (pid=0 or =1)
     * base on _maxmsg process as many order as possible
       it's Return the number of message send   
    ******************************************************/
    {

            // update  the Queue Statistic
            $this-> _getQueue();

            // organise order by priority
            $this->_orderfeed=self::_orderSort($this->_orderfeed, 'pid', SORT_ASC);

            $nbmsgsend=0;

            //echo "Pending orders : ".count($this->_orderfeed)."\n";
            //echo "Pending answer : ".count($this->_msgfeed)."\n";

            foreach ($this->_orderfeed as $id=>$order) {

                    //Traite tous les messages realtime
                    if ($order["pid"]<2) {
                            writelog ($this->_process." : Sending Message (".$id."): PID ".$order["pid"]." - mac : ".$order["mac"]." - command : ".$order["command"]);
                            $this->_msgSend($order["mac"],$order["command"],$order["stream"] );
                            $nbmsgsend++;

                            unset ($this->_orderfeed[$id]);
                            //si nombre de message traité < max message par cycle 
                            //alors traite les messages jusqu'a ce que nbmessage traités=maxmessage par cycle

                    } elseif (count($this->_msgfeed)<$this->_maxmsg) {
                            writelog ($this->_process." : Sending Message (".$id."): PID ".$order["pid"]." - mac : ".$order["mac"]." - command : ".$order["command"]);
                            $this->_msgSend($order["mac"],$order["command"],$order["stream"] );
                            $nbmsgsend++;
                            //echo $nbmsgsend." message(s) sent\n";
                            unset ($this->_orderfeed[$id]);

                    }else {
                            writelog ($this->_process." : Network Congestion - Pending answer : ".count($this->_msgfeed));
                            break;
                    }
            }
            return $nbmsgsend;
    }
        
    private function _orderPendingCount() 
    /******************************************************
     This function return the number of message
     pending in order feed and not submit yet
     to messages queue
    ******************************************************/
    {

            return count($this->_orderfeed);

    }	

    public function _orderSort($array, $on, $order=SORT_ASC)
    /******************************************************
     _orderSort
     This function ordering the pending in order feed 
    ******************************************************/
    {
        $new_array = array();
        $sortable_array = array();

        if (count($array) > 0) {
            foreach ($array as $k => $v) {
                if (is_array($v)) {
                    foreach ($v as $k2 => $v2) {
                        if ($k2 == $on) {
                            $sortable_array[$k] = $v2;
                        }
                    }
                } else {
                    $sortable_array[$k] = $v;
                }
            }

            switch ($order) {
                case SORT_ASC:
                    asort($sortable_array);
                break;
                case SORT_DESC:
                    arsort($sortable_array);
                break;
            }

            foreach ($sortable_array as $k => $v) {
                $new_array[$k] = $array[$k];
            }
        }

        return $new_array;
    }

    
    /*=====================================================
                    Answer to Order
     * Functiions below anser to order received
     * call from _orderReceive()
    =======================================================*/
    
    private function _answerGetDeviceCount() 
    /******************************************************
     Answer the Device Count to main program
     <plugwise get device count>
    ******************************************************/
    {

            $answer=array("DeviceCount",count($this->_devices));
            return $this->_socketSend($answer);
    }

    private function _answerGetMsgList() 
    /******************************************************
     Answer the pending messages list to main program
     <plugwise get message list>
    ******************************************************/
    {

            $header = array("mac","code","status","unknown","stream","datesent");
            $answer=$this->_msgfeed;
            array_unshift($answer, $header);
            return $this->_socketSend($answer);
    }

    private function _answerGetMsgCount() 
    /******************************************************
     Answer the pending messages count to main program
    ******************************************************/
    {

            $answer=array("PendingMsg",$this->_msgPendingCount());
            return $this->_socketSend($answer);
    }

    private function _answerGetOrderList() 
    /******************************************************
     Answer the pending orders list to main program
    ******************************************************/
    {

            $header = array("pid","mac","code","stream");
            $answer=$this->_orderfeed;
            array_unshift($answer, $header);
            return $this->_socketSend($answer);
    }

    private function _answerGetOrderCount()
    /******************************************************
     Answer the pending orders count to main program
    ******************************************************/
    {

            $answer=array("PendingOrder",$this->_orderPendingCount());
            return $this->_socketSend($answer);
    }

    private function _answerRequestDiscovery()
    /******************************************************
     Add a Request discovery and answer OK to main program
    ******************************************************/
    {

        $this->_requestDiscovery();

        $answer = "ok";
        return $this->_socketSend($answer);
     }

    private function _answerRequestInit()
    /******************************************************
     Add a Request Init and answer OK to main program
    ******************************************************/
    {

        $this->_requestInit();

        $answer = "ok";
        return $this->_socketSend($answer);
     }

    private function _answerRequestUpdateInfo()
    /******************************************************
     Add a Request Update Info and answer OK to main program
    ******************************************************/
    {

        $this->_requestUpdateInfo();

        $answer = "ok";
        return $this->_socketSend($answer);
     }

    private function _answerRequestUpdatePower() 
    /******************************************************
     Put a Request Update Power and answer OK to main program
    ******************************************************/
    {

        $this->_requestUpdatePowerInfo();

        $answer = "ok";
        return $this->_socketSend($answer);
     }

    private function _answerUnknown() 
    /******************************************************
     Default answer for unknown command to main program
    ******************************************************/
    {

        $answer = "command unknown";
        return $this->_socketSend($answer);
    }

    private function _answerHelp()
    /******************************************************
     display help to main program
    ******************************************************/
    {

        $answer = "plugwise help";
        return $this->_socketSend($answer);
    }

    private function _answerSetDeviceONOFF($order)
    /******************************************************
     Add request for Device On|Off and display action to main program
    ******************************************************/
    {
        $arr_order=explode(" ",$order);
        $macaddress = strtoupper($arr_order[2]);
        if($arr_order[3]=="off") {
                $switchonoff="00";
                $this->_requestDevicePowerswitch($macaddress,$switchonoff);
                $answer = "Switching ".$macaddress." OFF";
        }
        else {
                $switchonoff="01";
                $this->_requestDevicePowerswitch($macaddress,$switchonoff);
                $answer = "Switching ".$macaddress." ON";
                }	
        return $this->_socketSend($answer);
     }
    
    private function _answerGetDeviceList()
    /******************************************************
     Answer Device list to main program
    ******************************************************/
    {

    $devicelist=array(array("mac","type","hardware","firmware","clocktime"));
    foreach ($this->_devices as $mac=>$device) {
        $clocktime=$device["year"]."-".$device["month"]."-".$device["day"]." ".$device["hours"].":".$device["minutes"];
        $devicelist[]=	array (
                                 $mac
                                ,$device["type"]
                                ,$device["hardware"]
                                ,$device["firmware"]
                                ,$clocktime
                                );
    }
    $answer=$devicelist;
    return $this->_socketSend($answer);

    }

    private function _answerGetDevicePower() 
    /******************************************************
     Answer Device Power info to main program
    ******************************************************/
    {

        $devicelist=array(array("mac","power","powercontact","state"));
        foreach ($this->_devices as $mac=>$device) {
            $state ="...";
            if ($device["type"]<>"Plugwise(USBStick)") {

                if ($device["state"]<>NULL) { $state=$device["state"]==="01" ? 'On' : 'Off'; }
                $devicelist[]=	array (
                                        $mac
                                        ,$device["power"]
                                        ,$device["powercontact"]
                                        ,$state
                                        );
            }
        }
        $answer=$devicelist;
        return $this->_socketSend($answer);

    }
    
    /*=====================================================
                    Messages managment
     * this is messages on Plugwise mesh network management
     * through the _msgfeed array
    =======================================================*/
    
    private function _msgPendingCount() 
    /*********************************************
     Return number of pending message in _msgfeed
    *********************************************/
    {

            return count($this->_msgfeed);

    }

    public function _msgSearchToAck() 
    /*********************************************
     Return id of last message Sent to Ack 
     Return -1 if not found 
    *********************************************/
    {

            foreach ($this->_msgfeed as $idmsg=>$msg) {

                    if ($msg["status"]==self::SENT) {
                            return $idmsg;
                            break;
                    }
            }

            return -1;

    }

    public function _msgSearchSeq($seq) 
    /*********************************************
     Return id of the message base on seq number
     Return -1 if not found 
    *********************************************/
    {

        foreach ($this->_msgfeed as $idmsg=>$msg) {

            if ($msg["seq"]==$seq) {
                    return $idmsg;
                    break;
            }
        }

        return -1;

    }

    private function _msgCleanQueue() 
    /*********************************************
     This function clean pending message in 
     messageQueue when time is > _maxmsgwait
     Return number of message cleaned
    *********************************************/ 
    {
        //cleaning old message
        $nbclean=0;
        $now=time();
        foreach($this->_msgfeed as $id=>$msg){
            $msg_time=strtotime($msg["time"]);
            $msg_age=$now-$msg_time;
            if ($msg_age>$this->_maxmsgwait) {
                $nbclean++;
                writelog ($this->_process." : Message : ".$msg["id"]." too old");
                unset ($this->_msgfeed[$id]);
            }
        }
        return $nbclean;
    }  

    public function _msgSend($macaddress,$command,$stream,$retry=0) 
    /*********************************************
     Send message on Plugwise Mesh Network
     return  if failed 
    *********************************************/
    {

        // Create new entry in messages Feed
        $this->_msgfeed[]= array(
                        "mac"=>$macaddress
                        ,"command"=>$command
                        ,"status"=>self::SENT
                        ,"seq"=>null
                        ,"stream"=>$stream
                        ,"time"=>$this->_now()
                        ,"retry"=>$retry
                        );

        // Calculate CRC
        $crc=self::_CrcCheckSum($stream);

        // Create message
        $msg=$stream.$crc;

        // Send message on Network
        $this->serial->sendMessage($this->_HF_.$msg.$this->_EF_);

        // debug messages	
        //writelog ($this->_process.": Send command ".$command." to ".$macaddress);
        //writelog ($this->_process.": Sending Command: ".$stream." crc :".$crc." to ".$macaddress);
    }


    public function _msgRead() 
    /*********************************************
    Read message from Plugwise Mesh Network
    Return message read in an array containing :
    $feed (code1, seq2, message stream1
            code2, seq2, message stream2
            ect..)
    Example : 0013 | 125 | 0022522656565623565646562
    *********************************************/
    {

        $feed=array();

        //read Serial buffer	
        $read = $this->serial->readPort();

        if ($read<>'') {

            $lines=explode($this->_EF_,$read);

            foreach($lines as $line) 
            {
                // Remove header	
                $line=trim(str_replace($this->_HF_,'',$line));
                //remove strange 0x83 char
                $line=trim(str_replace(chr(0x83),'',$line));

                if(substr($line,0,21)=="# APSRequestNodeInfo:") {
                        //writelog ($this->_process." : Receive Network info : ".$line);
                }
                //ignore emply line and line to short
                elseif ($line<>'' & strlen($line)>=16) 
                {
                    //split crc from stream
                    $stream=substr($line,0,strlen($line)-4);
                    $crc=substr($line,-4);
                    $crc_check=self::_CrcCheckSum($stream);

                    //split Code; Sequence and Message 
                    $code=substr($stream,0,4);
                    $seq=hexdec(substr($line,4,4));
                    $msg = substr($stream, 8);

                    if ($crc==$crc_check) {
                    // Valid message
                    //writelog ($this->_process." : Receive Valid message from Network - code :".$code." - seq :".$seq." - msg :".$msg);
                        $feed[] = array(
                                "code"=>$code
                                ,"seq"=>$seq
                                ,"msg"=>$msg
                                );

                    } else {
                            // Invalid message (crc error)
                            //writelog ($this->_process." : *******CRC Error************ ");
                            //writelog ($this->_process." : Receive : ".$line);
                    }

                } else {
                //empty line or too short
                //show debug message or just ignore it ??
                }
            }
        }
        return $feed;		
    }	

    public function _msgProcess($feed) 
    /*********************************************
     Read $feed array (code, seq, message stream)
     and call process function associated to each code
     and provide seq and message stream associated
    *********************************************/
    {
        
        foreach($feed as $arr_msg) {
            $code=$arr_msg["code"];
            $seq=$arr_msg["seq"];
            $msg=$arr_msg["msg"];
            switch($code) {
                //Ack message
                 case self::ACKMSG :
                        $this->_processAcknowledgment($seq,$msg);
                        break;
                 case self::INIT_ANSWER :
                        $this->_processInitStick($seq,$msg);
                        break;
                case self::DISCOVERY_ANSWER :
                        $this->_processDiscovery($seq,$msg);
                        break;
                case self::POWERINFO_ANSWER :
                        $this->_processDevicePowerInfo($seq,$msg);
                        break;
                case self::CALIBRATION_ANSWER :
                        $this->_processDeviceCalibration($seq,$msg);
                        break;											
                case self::DEVICEINFO_ANSWER :
                        $this->_processDeviceInfo($seq,$msg);
                        break;
                case self::CLOCKGET_ANSWER :
                        $this->_processDeviceClockInfo($seq,$msg);
                        break;
                default:
                        break;
                }
                //writelog ($this->_process.": Code : $code - seq : $seq - $msg - CRC OK [$crc]");
        }
    }	
    

    /*=====================================================
                    Request order functions
            This function add requests in Orderfeed
            they will be proceed in next cycles
    =======================================================*/
    
    public function _requestDiscovery() 
    /******************************************************
      Add discovery request for all nodes to _orderfeed 
    *******************************************************/
    {

        $command=self::DISCOVERY;
        $macaddress=$this->_mcmac;

        for ($nodeid=00; $nodeid<=63; $nodeid++){
                $stream=$command.$macaddress.sprintf("%02X", $nodeid);

                $this->_orderfeed[]=array(
                        "pid"=>"10"
                        ,"mac"=>$macaddress
                        ,"command"=>$command
                        ,"stream"=>$stream
                );

        }
    }

    public function _requestInit() 
    /******************************************************
      Add an init network order to _orderfeed 
    *******************************************************/            
    {

        $command=self::INIT;

        $stream=$command;
        $macaddress= null;

        $this->_orderfeed[]=array(
                        "pid"=>"0"
                        ,"mac"=>$macaddress
                        ,"command"=>$command
                        ,"stream"=>$stream
                );
    }


    public function _requestDevicePowerswitch($macaddress,$switchonoff)	
    /******************************************************
      Add powerswitch ON/OFF order for a MAC  to _orderfeed
    *******************************************************/            
    {
        $command=self::POWERSTATE;
        $stream=$command.$macaddress.$switchonoff;

        $this->_orderfeed[]=array(
                        "pid"=>"1"
                        ,"mac"=>$macaddress
                        ,"command"=>$command
                        ,"stream"=>$stream
                );
    }

    public function _requestDevicePowerinfo($macaddress)
    /******************************************************
      Add powerInfo Request order for a MAC  to _orderfeed
    *******************************************************/
    {

            $command=self::POWERINFO;
            $stream=$command.$macaddress;

            $this->_orderfeed[]=array(
                            "pid"=>"5"
                            ,"mac"=>$macaddress
                            ,"command"=>$command
                            ,"stream"=>$stream
                    );

    }

   public function _requestClockSet($macaddress)
    /******************************************************
      Add ClockSet Request order for a MAC  to _orderfeed
    *******************************************************/
    {

            $command=self::CLOCKSET;
            
            $now = new DateTime();
            
            $year = strtoupper(dechex(intval($now->format('Y'))-2000));
            $xyear= str_pad($year, 2, '0', STR_PAD_LEFT);
            $month = strtoupper(dechex($now->format('m')));
            $xmonth = str_pad($month, 2, '0', STR_PAD_LEFT);
            
            $day = $now->format('d');
            $hour = $now->format('H');
            $minute = $now->format('i');
            $sec = $now->format('s');
            $dow = $now->format('N');
            
            $xhour = str_pad(strtoupper(dechex($hour)), 2, '0', STR_PAD_LEFT);
            $xminute = str_pad(strtoupper(dechex($minute)), 2, '0', STR_PAD_LEFT);
            $xsec = str_pad(strtoupper(dechex($sec)), 2, '0', STR_PAD_LEFT);
            $xdow = str_pad(strtoupper(dechex($dow)), 2, '0', STR_PAD_LEFT);
            
            $fullminutes = strtoupper(dechex($minutes + 60 * ($hours + (($day-1 ) * 24))));
            $xfullminutes = str_pad($fullminutes, 4, '0', STR_PAD_LEFT);
            
            writelog ($this->_process." : Year :$xyear Month:$xmonth FullMinutes:$xfullminutes H: $xhour M: $xminute S:$xsec DOW: $xdow");
            $stream=$command.$macaddress.$xyear.$xmonth.$xfullminutes.'FFFFFFFF'.$xhour.$xminute.$xsec.$xdow;
            writelog ($this->_process." : stream : $stream");
            $this->_orderfeed[]=array(
                            "pid"=>"0"
                            ,"mac"=>$macaddress
                            ,"command"=>$command
                            ,"stream"=>$stream
                    );

    }


    
    public function _requestDeviceCalibration($macaddress)
    /******************************************************
     Add Calibration Request order for a MAC to _orderfeed 
    *******************************************************/
    {

        $command=self::CALIBRATION;
        $stream=$command.$macaddress;

        $this->_orderfeed[]=array(
                        "pid"=>"5"
                        ,"mac"=>$macaddress
                        ,"command"=>$command
                        ,"stream"=>$stream
                );

    }

    public function _requestDeviceInfo($macaddress)
    /******************************************************
     Add DeviceInfo Request order for a MAC to _orderfeed
     This is to get  Firmware, State, ...
    *******************************************************/
    {

        $command=self::DEVICEINFO;
        $stream=$command.$macaddress;
        $this->_orderfeed[]=array(
                        "pid"=>"2"
                        ,"mac"=>$macaddress
                        ,"command"=>$command
                        ,"stream"=>$stream
                );
    }

    public function _requestClockInfo($macaddress)
    /******************************************************
     Add Device Get Clock Info order for a MAC to _orderfeed
     This is to get clock info
    *******************************************************/
    {
        $command=self::CLOCKGET;
        $stream=$command.$macaddress;
        $this->_orderfeed[]=array(
                        "pid"=>"8"
                        ,"mac"=>$macaddress
                        ,"command"=>$command
                        ,"stream"=>$stream
                );
    }


    public function _requestUpdateInfo()
    /******************************************************
     Request DeviceInfo for all discovered Device
    *******************************************************/
    {

        foreach ($this->_devices as $macaddress=>$device) {

            if( $macaddress<>$this->_stickmac) {
                    $this->_requestDeviceInfo($macaddress);
            }
        }

    }

    public function _requestUpdatePowerInfo()
    /******************************************************
     Request DevicePowerinfo for Each discovered Device
    *******************************************************/
    {

        foreach ($this->_devices as $macaddress=>$device) {

            if( $macaddress<>$this->_stickmac) {
                    $this->_requestDevicePowerinfo($macaddress);
            }
        }

    }

    
    /*=====================================================
                    Process Functions
     Those functions process messages received
     from plugwise Mesh Network called bym _msgProcess
    =======================================================*/
    
    public function _processAcknowledgment($seq,$msg)
    /******************************************************
     Process an acknowledge message 
    *******************************************************/
    {

        $idmsg=self::_msgSearchSeq($seq);

        if ($idmsg==-1) {
                $idmsg=self::_msgSearchToAck();
        }

        if ($idmsg<>-1) {
            writelog ($this->_process." : Command ".$this->_msgfeed[$idmsg]["command"]." to ".$this->_msgfeed[$idmsg]["mac"]." ACKNOWLEDGE. Seq=".$seq);
            $this->_msgfeed[$idmsg]["status"]=self::ACK;
            $this->_msgfeed[$idmsg]["seq"]=$seq;

            // receive a nack error we need to inform puppet master
            if ($msg==self::NACK) {

                $this->_msgfeed[$idmsg]["status"]=self::NACK;

                // We ignore NACK message from discovery process... too much noise !
                if($this->_msgfeed[$idmsg]["command"]<>self::DISCOVERY) {

                    // retry message ?
                    if ($this->_msgfeed[$idmsg]["retry"]<3) {
                        $this-> _msgSend($this->_msgfeed[$idmsg]["mac"],$this->_msgfeed[$idmsg]["command"],$this->_msgfeed[$idmsg]["stream"],$this->_msgfeed[$idmsg]["retry"]+1);			
                    }

                }
                unset ($this->_msgfeed[$idmsg]);

            }

        }

    }

    public function _processInitStick($seq,$msg)
    /******************************************************
     Process an init stick message 
    *******************************************************/
    {

    $idmsg=self::_msgSearchSeq($seq);			

    if ($idmsg<>-1) {

        //update message feed array
        $this->_msgfeed[$idmsg]["status"]=self::RECEIVED;
        $this->_msgfeed[$idmsg]["stream"]=$msg;

        //update stick and circle+ Mac Address		
        $this->_stickmac=substr($msg, 0, 16);
        $this->_mcmac="00".substr($msg, 22, 14);

        writelog ($this->_process.": INIT answer : Stick Mac set to : ".$this->_stickmac);
        writelog ($this->_process.": INIT answer : Circle+ Mac set to : ".$this->_mcmac);

        $this->_devices[$this->_stickmac]=$this->_device_tpl;
        $this->_devices[$this->_stickmac]["type"]="Plugwise(USBStick)";
        $this->_devices[$this->_stickmac]["infocontact"]=$this->_now();
        $this->_devices[$this->_stickmac]["inforequest"]=$this->_now();
        
        $this->_devices[$this->_mcmac]=$this->_device_tpl;
        $this->_devices[$this->_mcmac]["type"]="Plugwise(Circle+)";
                                        
        $this->_setMaxQueue();
        $this->_requestDeviceCalibration($this->_mcmac);
        unset ($this->_msgfeed[$idmsg]);
        //launch Network discovery
        $this->_requestDiscovery();
        }	
    }

    public function _processDiscovery ($seq,$msg)
    /******************************************************
     Process a New device discovery message 
    *******************************************************/
    {	

        $idmsg=self::_msgSearchSeq($seq);			
        if ($idmsg<>-1) {
            // update message feed array
            $this->_msgfeed[$idmsg]["status"]=self::RECEIVED;
            $this->_msgfeed[$idmsg]["stream"]=$msg;

            $macaddress=substr($msg,16,16);
            if($macaddress<>"FFFFFFFFFFFFFFFF") {
                writelog ($this->_process." : New device discovered - Mac : ".$macaddress);
                $this->_devices[$macaddress]=$this->_device_tpl;

                if ($this->_devices[$macaddress]["type"]<> "Plugwise(USBStick)") {
                        $this->_setMaxQueue();
                        $this->_requestDeviceCalibration($macaddress);
                        $this->_requestDeviceInfo($macaddress);
                }	
            }
            unset ($this->_msgfeed[$idmsg]);
        }
    }

    public function _processDeviceClockInfo($seq,$msg)
    /******************************************************
     Process device Clock Info message
    ******************************************************/
    {
        
        $idmsg=self::_msgSearchSeq($seq);

        if ($idmsg<>-1) {

            // update message feed array
            $this->_msgfeed[$idmsg]["status"]=self::RECEIVED;
            $this->_msgfeed[$idmsg]["stream"]=$msg;

            $macaddress = substr($msg,0,16);
            $hours = intval(hexdec(substr($msg, 16, 2)));
            $minutes = intval(hexdec(substr($msg, 18, 2)));
            $secondes = intval(hexdec(substr($msg, 20, 2)));
            $day_of_week = intval(hexdec(substr($msg, 22, 2)));

            $this->_devices[$macaddress]["clock_h"]=$hours;
            $this->_devices[$macaddress]["clock_m"]=$minutes;
            $this->_devices[$macaddress]["clock_s"]=$secondes;
            $this->_devices[$macaddress]["clock_d"]=$day_of_week;

            writelog ($this->_process." : Clock Info - Mac : ".$macaddress." Date : ".$hours.":".$minutes.":".$secondes."-".$day_of_week);
            unset ($this->_msgfeed[$idmsg]);

        }
    }

    public function _processDevicePowerInfo($seq,$msg)
    /******************************************************
     Process device Power Info message
    ******************************************************/
    {

        $idmsg=self::_msgSearchSeq($seq);		

        if ($idmsg<>-1) {

            // update message feed array
            $this->_msgfeed[$idmsg]["status"]=self::RECEIVED;
            $this->_msgfeed[$idmsg]["stream"]=$msg;

            $macaddress=substr($msg,0,16);
            $pulses1 = intval(hexdec(substr($msg, 16, 4)));
            $pulses8 = intval(hexdec(substr($msg, 20, 4)));
            $pulsetotal = intval(hexdec(substr($msg, 24, 8)));

            writelog ($this->_process." : Power Info - Mac : ".$macaddress." Pulse 1 :".$pulses1." Pulse 8 :".$pulses8." Pulse Total : ". $pulsetotal);
            $this->_devices[$macaddress]["pulsesInterval1"]=$pulses1;
            $this->_devices[$macaddress]["pulsesInterval8"]=$pulses8;
            $this->_devices[$macaddress]["pulsesTotal"]=$pulsetotal;

            //print_r ($this->_devices[$macaddress]);
            $pulses=self::_pulsesCorrection (
                    $this->_devices[$macaddress]["pulsesInterval1"],
                    1,
                    $this->_devices[$macaddress]["offRuis"],
                    $this->_devices[$macaddress]["offTot"],
                    $this->_devices[$macaddress]["gainA"],
                    $this->_devices[$macaddress]["gainB"]
            );

            $Watt=self::_pulsesToWatt($pulses);

            $this->_devices[$macaddress]["power"]=sprintf("%-01.2f",$Watt);
            $this->_devices[$macaddress]["powercontact"]=$this->_now();
            writelog ($this->_process." : Power Info - Mac : ".$macaddress." Conso (Watt) : ".sprintf("%-01.2f",$Watt)." Watt");
            unset ($this->_msgfeed[$idmsg]);
        }		
    }

    public function _processDeviceCalibration($seq,$msg)
    /******************************************************
     Process device calibration message
    ******************************************************/
    {

        $idmsg=self::_msgSearchSeq($seq);			
        if ($idmsg<>-1) {
            // update message feed array
            $this->_msgfeed[$idmsg]["status"]=self::RECEIVED;
            $this->_msgfeed[$idmsg]["stream"]=$msg;
            $macaddress=substr($msg,0,16);
            $gainA = self::_hexToFloat(substr($msg, 16, 8));
            $gainB = self::_hexToFloat(substr($msg, 24, 8));
            $offTot = self::_hexToFloat(substr($msg, 32, 8));
            $offRuis = self::_hexToFloat(substr($msg, 40, 8));
            $this->_devices[$macaddress]["gainA"]=$gainA;
            $this->_devices[$macaddress]["gainB"]=$gainB;
            $this->_devices[$macaddress]["offTot"]=$offTot;
            $this->_devices[$macaddress]["offRuis"]=$offRuis;
            writelog ($this->_process." : Calibration ".$msg);
            unset ($this->_msgfeed[$idmsg]);
        }		
    }

    public function _processDeviceInfo($seq,$msg)
    /******************************************************
     Process device Info message
    ******************************************************/
    {

        $idmsg=self::_msgSearchSeq($seq);
        if ($idmsg<>-1) {
            //echo "DeviceInfo : $msg\n";
            $this->_msgfeed[$idmsg]["status"]=self::RECEIVED;
            $this->_msgfeed[$idmsg]["stream"]=$msg;
            $macaddress=substr($msg,0,16);
            $year=2000+intval(hexdec(substr($msg, 16, 2)));
            $month=intval(hexdec(substr($msg, 18, 2)));
            
            $full_minutes=intval(hexdec(substr($msg, 20, 4)));
            $str_date=$year."-".$month."-01 00:00";
            
            try {
                $date_obj=New DateTime($str_date);    
            } 
            catch (Exception $ex) {
                $str_date="2010-01-01 00:00";
                $date_obj=New DateTime($str_date);
            }
            
            $date_obj->add(new DateInterval('PT' . $full_minutes . 'M'));
            $year=$date_obj->format('Y');
            $month=$date_obj->format('m');
            $day=$date_obj->format('d');
            $hours=$date_obj->format('H');
            $minutes=$date_obj->format('i');
            
            $currentlog=(intval(hexdec(substr($msg, 24, 8)))- 278528) / 32;
            $currentstate=substr($msg, 32, 2);
            $hertz=substr($msg, 34, 2);
            $hardware=sprintf("%s-%s-%s",substr($msg, 36, 4),substr($msg, 40, 4),substr($msg, 44, 4));
            $firmware=date('d/m/Y', intval(hexdec(substr($msg, 48, 8))));
            $c1=substr($msg, 60, 2);

            $this->_devices[$macaddress]["year"]=$year;
            $this->_devices[$macaddress]["month"]=$month;
            $this->_devices[$macaddress]["day"]=$day;
            $this->_devices[$macaddress]["hours"]=$hours;
            $this->_devices[$macaddress]["minutes"]=$minutes;
            $this->_devices[$macaddress]["currentlog"]=$currentlog;
            $this->_devices[$macaddress]["state"]=$currentstate;
            $this->_devices[$macaddress]["herz"]=$herz;
            $this->_devices[$macaddress]["hardware"]=$hardware;
            $this->_devices[$macaddress]["firmware"]=$firmware;
            $this->_devices[$macaddress]["infocontact"]=$this->_now();
            //var_dump($this->_devices[$macaddress]);
            unset ($this->_msgfeed[$idmsg]);
        }		
    }
    
    
    /*=====================================================
                    Send Message to Parent IPC
    =======================================================*/
    
    private function _socketSend($answer) {

        $serial_answer = serialize($answer)."\n";
        if (socket_write($this->_socket, $serial_answer, strlen($serial_answer)) === false) {
            writelog ("socket_write() a échoué. Raison : ".socket_strerror(socket_last_error($this->_psocket)));
            return 1;
        }
        else {
            writelog ($this->_process." : Sending answer");
            return 0;
        }
    }

    /*=====================================================
                Return CRC Checksum of the String 
    =======================================================*/

    public function _CrcCheckSum($string) {

            $crc = 0x0000;

            for ($i = 0, $j = strlen($string); $i < $j; $i++) {
                $x = (($crc >> 8) ^ ord($string[$i])) & 0xFF;
                $x ^= $x >> 4;
                $crc = (($crc << 8) ^ ($x << 12) ^ ($x << 5) ^ $x) & 0xFFFF;
            }

            return strtoupper(sprintf("%04X",$crc));
    }

    /*=====================================================
                Configure the Serial port used  
    =======================================================*/

     public function configure($port) {
                    $this->_port=$port;
     }


    /*********************************************
            Return Float from an Hexadecimale String 
    *********************************************/	

     private function _hexToFloat($hex){

            $intval=hexdec($hex);
            $bits = pack("L",$intval);
            $float_arr = unpack("f", $bits);

            return $float_arr[1];
     }


    /*********************************************
     Return now datetime string formated 
    *********************************************/	
     private function _now(){

            return date('Y-m-d H:i:s'); 	

     }

    /*********************************************
     this function is used for network health stats	
     it set the Max Message queue base on number of device 
    *********************************************/	
     private function _setMaxQueue() {

            if (count($this->_devices<2)) {
                    $this->_maxqueue =0;				
            } else {
                    $this->_maxqueue = $this->_maxmsg*(count($this->_devices)-1);
            }

     }

    /*********************************************
     this function is used for network health stats
     get % Message queue utilization base on pending order 
    *********************************************/	
    private function _getQueue() {

            if ($this->_maxqueue ==0) {
                    $calc=0;
            }else {
                    $calc = $this->_orderPendingCount()*100/$this->_maxqueue;
            }
            return $calc;
    }


    /*********************************************
     Return Corrected Pulses 
    *********************************************/

    private function _pulsesCorrection($pulses, $timespan,$offRuis, $offTot, $gainA, $gainB) {

            if ($pulses==0) {
                    $out=0;

            } else {
                    $value= $pulses/$timespan;
                    $out =1 * (((pow($value + $offRuis, 2.0) * $gainB) + (($value + $offRuis) * $gainA)) + $offTot);
            }

            return $out;
    } 

    /*********************************************
     Return Kwh from Pulses 
    *********************************************/

    private function _pulsesToKwh( $pulses) {

            $result =($pulses/ 1) / 468.9385193;

            return $result;
    }

    /*********************************************
     Return Watt from pulses 
    *********************************************/

    private function _pulsesToWatt($pulses) {

            $result=self::_pulsesToKwh($pulses)*1000;

            return $result;

    }

    public function _events() {    
    }


    private function _taskUpdate() {

        foreach ($this->_devices as $mac=>$device) {
            if ($device["type"]<> "Plugwise(USBStick)") {

                $now=time();
                if ($device["powerrequest"]==NULL)
                {
                    $lastrequest=$now-$this->_pollPower-1;
                    //writelog ("Task Update : $mac - set last request");
                } 
                else 
                {
                    $lastrequest=strtotime($device["powerrequest"]);
                    //writelog ("Task Update : $mac - set last request");
                }

                $diffrequest=$now - $lastrequest + Rand(-2,2);

                if (($device["powercontact"]==NULL) && ($diffrequest>$this->_pollPower))
                {
                    writelog ($this->_process." : TaskUpdate : $mac - Force update power");
                    $this->_devices[$mac]["powerrequest"]=$this->_now();
                    $this->_requestDevicePowerInfo($mac);

                } 
                elseif ($device["powercontact"]!==NULL) 
                {
                    $lastupdate=strtotime($device["powercontact"]);
                    $diffcontact=$now - $lastupdate;

                    if (($diffcontact>$this->_pollPower) &&($diffrequest>$this->_pollPower) ) 
                    {
                        writelog ($this->_process." : TaskUpdate : $mac - Need update power");		
                        $this->_requestDevicePowerInfo($mac);
                        $this->_devices[$mac]["powerrequest"]=$this->_now();
                    }
                }
                

                if ($device["inforequest"]==NULL) 
                {
                    $lastrequest=$now-$this->_pollInfo-1;

                } 
                else 
                {
                        $lastrequest=strtotime($device["inforequest"]);
                }

                $diffrequest=$now - $lastrequest + Rand(-2,2);

                if (($device["infocontact"]==NULL) && ($diffrequest>$this->_pollInfo))
                {

                    $this->_devices[$mac]["inforequest"]=$this->_now();
                    $this->_requestDeviceInfo($mac);

                }
                elseif ($device["infocontact"]!==NULL) 
                {

                    $lastupdate=strtotime($device["infocontact"]);
                    $diffcontact=$now - $lastupdate;

                    if (($diffcontact>$this->_pollInfo) &&($diffrequest>$this->_pollInfo) ) 
                    {
                        writelog ($this->_process." : TaskUpdate : $mac - Need update info");
                        $this->_requestDeviceInfo($mac);
                        $this->_devices[$mac]["inforequest"]=$this->_now();
                    }
                }
                
                /*--------------------------------------------------------------
                 *          Clock Update
                 *------------------------------------------------------------*/
                if ($device["clockrequest"]==NULL)
                {
                    $lastrequest=$now-$this->_pollClock-1;
                } else {
                    $lastrequest=strtotime($device["clockrequest"]);
                }

                $diffrequest=$now - $lastrequest + Rand(-2,2);

                if (($device["clockcontact"]==NULL) && ($diffrequest>$this->_pollClock))
                {
                    writelog ($this->_process." : TaskUpdate : $mac - Force clock update");
                    $this->_requestClockSet($mac);
                    $this->_devices[$mac]["clockrequest"]=$this->_now();
                } 
                elseif ($device["clockcontact"]!==NULL) 
                {
                    $lastupdate=strtotime($device["clockcontact"]);
                    $diffcontact=$now - $lastupdate;

                    if (($diffcontact>$this->_pollClock) &&($diffrequest>$this->_pollClock) ) 
                    {
                        writelog ($this->_process." : TaskUpdate : $mac - Need clock update");
                        $this->_requestClockSet($mac);
                        $this->_devices[$mac]["clockrequest"]=$this->_now();
                    }
                }
            }	
        }
    }
    
    /*************************
    /* template _process function
    *************************
    public function _processxxxxxx($seq,$msg) {

                    $idmsg=self::_msgSearchSeq($seq);			
                    if ($idmsg<>-1) {
                            $this->_msgfeed[$idmsg]["status"]=self::RECEIVED;
                            $this->_msgfeed[$idmsg]["stream"]=$msg;
                            Decode answer
                            Store information in array
                            unset ($this->_msgfeed[$idmsg]);
                    }		
            }
    */

// end of class plugwise_usb
}	

?>
