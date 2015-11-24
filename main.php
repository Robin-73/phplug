#!/usr/bin/php
<?php
	
    const _MODULES_PATH_="./modules/";
    const _LOGFILE_PATH_="./log/";

    $TERMINATE  = false;
    $RELOAD	= false;

    $process    = array();

    declare(ticks = 1);
    pcntl_signal(SIGTERM, "sig_handle");
    pcntl_signal(SIGINT, "sig_handle");
    pcntl_signal(SIGHUP, "sig_handle");


    function sig_handle($signal)
    {
		global $TERMINATE;
		switch($signal)
		{
			case SIGTERM:
				writelog ("Got SIGTERM");
				$TERMINATE = true;
			break;
			case SIGINT:
				writelog ("User pressed Ctrl+C - Got SIGINT");
				$TERMINATE = true;
			break;
			case SIGHUP:
				writelog ("Got SIGHUP");
				$TERMINATE = false;
				$RELOAD = true;
			break;
		}
    }


    function writeLog($data) 
    {
        list($usec, $sec) = explode(' ', microtime());
        $datetime = strftime("%Y-%m-%d %H:%M:%S",time());
        $log_file = _LOGFILE_PATH_.date("Y-m-d")."_logfile.log"; 
        $msg = $datetime."-". sprintf("%06s",intval($usec*1000000)).": $data";
        $fp = @fopen($log_file, 'a'); 
        fputs($fp, "$msg\n"); 
        fclose($fp); 
    }
		
    function load_module($module_info) 
    {
        global $process;
        $process[$module_info["name"]]=$module_info;
    }  
	
    /***************************************************/
    /* This class manage Telnet connection and answer */
    /***************************************************/
	
    class clientHandler{
		  
        public $bClosed = false;
        public $sckSocket = false;
        public $cmd = false;
        public $command = false;
        public $parameter = false;
        public $dtarr = array();
        
        public function clientHandler($sckSocket){
            $this->sckSocket = $sckSocket;
            $this->send('<OK>');
        }
        
        public function handle(){
            global $process;
	    global $TERMINATE;	

            $cmd = $this->read();
            
            if($cmd){
                @list($command, $parameter) = @explode(' ', $cmd, 2);
            }
            
            if(isset($command) && $command){
                switch($command){
                    case 'time':
                        $this->send(date('Y-m-d H:i:s'));
                        break;
		    case 'plugwise':
			/*************************************
			*	Sending request
	 		*************************************/
	 		$sent_parameter=$parameter."\n";
                        
			if (socket_write($process ["plugwise"]["socket"][1], $sent_parameter, strlen($sent_parameter)) === false) {
                            $this->send("socket_write() a échoué. Raison : ".socket_strerror(socket_last_error($process ["plugwise"]["socket"][1])));
			} 
                        
                        else {
                            /***********************************
                            *  Reading answser
                            ***********************************/
                            $answer = socket_read($process ["plugwise"]["socket"][1], 65535, PHP_NORMAL_READ);
                            
                            // Check Error Socket
                            if($answer === false) {
                                $error = socket_last_error($process ["plugwise"]["socket"][1]);
                                if($error != 11 && $error != 115) {
                                    $this->send("error while reading : ".socket_strerror($error).$error."\n");
                                }
                            //ignore Empty answer
                            } elseif($answer === NULL) {
                                $this->send("answer is empty\n");
                                    //managing Answer
                            } else {
                                //spliting all line of answers
                                $serialize_answer_arr = preg_split('/\n|\r\n?/', $answer,PREG_SPLIT_NO_EMPTY);
                                //managing each line of answer
                                foreach ($serialize_answer_arr as $serialize_line) {
                                   //checking not empty
                                   if (trim($serialize_line)<>'') {
                                        //the answer
                                        $answer_obj=unserialize($serialize_line);
                                        //answer could be array or string depending on initial request
                                        $answer_type=gettype($answer_obj);
                                        switch ($answer_type) {
                                                case "array" :
                                                        foreach ($answer_obj as $id=>$objet) {
                                                                $display="";
                                                                if (is_array($objet)) {
                                                                        foreach ($objet as $field) {
                                                                                $display.=$field.";";
                                                                        }
                                                                } else {
                                                                        $display=$objet;
                                                                }
                                                                $this->send($display);
                                                        }
                                                        break;
                                                        
                                                case "boolean":
                                                case "string":
                                                case "integer":
                                                case "double":
                                                                $display=$answer_obj;
                                                                $this->send($display);
                                                        break;
                                                default:
                                                        $display="unknown objet type : ". gettype($answer_obj);
                                                        $this->send($display);	
                                        }
                                   }   
                                }
                                $this->send("<fin>");	
                            }
                        }
                        break;
                    case 'exit':
                    case 'quit':
                        $this->disconnect();
                        break;
                    case 'shutdown':
                    case 'stop':
                        $this->send('Shutting down...');
                        $TERMINATE=True;
                        $this->disconnect();
                        break;
                    default:
                        $this->send('unknown command');
                }
            }
        }
        
        public function send($sMessage){
            @socket_write($this->sckSocket, $sMessage."\n");
        }
        
        public function read(){
            return trim(@socket_read($this->sckSocket, 65535));
        }
        
        public function disconnect(){
            $this->send('bye');
            $this->bClosed = true;
            socket_shutdown($this->sckSocket);
            socket_close($this->sckSocket);
        }
    }
	
    /*********************************************************************************
                    Main program start here
    *********************************************************************************/

    /* Loading modules */
    
    include (_MODULES_PATH_."plugwise_usb.php");

    writelog ("Starting Main Program...");

    foreach ($process as $processname=>$module) {
        
        writelog("Creating IPC Socket for module $processname ...");	
        
        /*  Trying to create socket pair */
        /*  Unable to create socket pair */
        if (socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $process [$processname]["socket"]) === false) {
            writelog("socket_create_pair() a échoué. Raison : ".socket_strerror(socket_last_error()));
        } 
        
        /*  Socket pair successfully created */
        else {
            writelog("opening socket pair for $processname : OK");
            
            /* Forking module*/
             $pid = pcntl_fork();

             /***********************************
             /*  Starting modules (child)
             ***********************************/
             if (!$pid) {
                     switch($module["bootstrap"]) {
                     case $process[$processname]["bootstrap"] :
                         writelog("Starting module $processname ...");	
                         include (_MODULES_PATH_.$process[$processname]["driver"]);
                         writelog ($process[$processname]["bootstrap"].": finished");  
                         exit(0);
                         /* Module (child) finish here */	
                         break;
                     default:	
                         writelog("Unknown process : ".$processname. ", exiting");
                         exit(1);
                     break;
                     }
             }
             /***********************************
             /*    Parent continue here
             ***********************************/
             else {
                     // we are in parent so we count process launch
                     writelog ("Starting ".$process[$processname]["bootstrap"]." module(".$processname.") as pid : ".$pid."...");
                     $childs[] = $pid;
                     //we close child socket
                             socket_close($process [$processname]["socket"][0]);     			
             }

         }
    }
    /***********************************
     Main parent does processing here...
    ***********************************/

    writelog("All Modules launched.");


    set_time_limit(0);
    
    /*Creating Telenet server on TCP 8080 */
    $sckMain = socket_create(AF_INET, SOCK_STREAM, 0) or die();
    socket_set_option($sckMain, SOL_SOCKET, SO_REUSEADDR, 1) or die();
    socket_bind($sckMain, '127.0.0.1', '8080') or die();
    socket_listen($sckMain) or die();
    $oaClients = array();

    /***************************************************************************************  
    *		 Main loop	Program     
    ***************************************************************************************/
    writelog ("Running main process");

    while(!$TERMINATE){
            $sckaRead = array();
            $sckaRead[0] = $sckMain;

            foreach($oaClients as $x => $oClient){
                    if($oClient->bClosed == true){
                            unset($oaClients[$x]);
                    }else{
                            $sckaRead[] = $oClient->sckSocket;
                    }
            }

            @socket_select($sckaRead, $null = NULL, $null = NULL,0);

            /* New Connection from client */
            if(in_array($sckMain, $sckaRead)){
                    $sckNewClient = socket_accept($sckMain);
                    $oaClients[] = new clientHandler($sckNewClient);
            }
            /* Manage each connected client */
            foreach($oaClients as $oClient){
                    if(in_array($oClient->sckSocket, $sckaRead)){
                            $oClient->handle();
                    }
            }
            usleep(100000);
    }

    writelog("Send terminate Signal to all modules");
    // Send KILL Signal to Child module
    posix_kill($pid, SIGTERM);

    /***************************************************************************************	
    /* Closing  all socket opened with child process
    ***************************************************************************************/
    socket_close($sckMain);
    
    foreach ($process as $processname=>$module) {
            socket_close($process [$processname]["socket"][1]);
    }
    /***************************************************************************************
     wait all child to finish
    ***************************************************************************************/
    while(count($childs) > 0) {
            foreach($childs as $key => $pid) {
                    $res = pcntl_waitpid($pid, $status, WNOHANG);

                    // If the process has already exited
                    if($res == -1 || $res > 0)
                            unset($childs[$key]);
            }
         sleep(1);
    }
    // end of the main program	
    writelog ("End of program : OK");

?> 
