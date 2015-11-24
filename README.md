# phplug
PHP USB Plugwise Library

* create /opt/phplug
* create /opt/phplug/log

`stty ispeed 115200 ospeed 115200 cs8 -parenb raw -iexten -echo -echoe -echok -echoctl -echoke < /dev/ttyUSB0`
`nohup /opt/phplug/main.php& >main.out 2>&1`

connect using telnet 127.0.0.1 8080

available commands :

- get device list
- get device count
- get device power
- get message list
- get message count
- get order list
- get order count
- request discovery
- request init
- request info update
- request power update
- set device <mac> on | off
