# phplug
PHP USB Plugwise Library

* create /opt/phplug
* create /opt/phplug/log

`stty ispeed 115200 ospeed 115200 cs8 -parenb raw -iexten -echo -echoe -echok -echoctl -echoke < /dev/ttyUSB0`
`nohup /opt/phplug/main.php& >main.out 2>&1`

connect using telnet 127.0.0.1 8080

available commands :

- plugwise get device list
- plugwise get device count
- plugwise get device power
- plugwise get message list
- plugwise get message count
- plugwise get order list
- plugwise get order count
- plugwise request discovery
- plugwise request init
- plugwise request info update
- plugwise request power update
- plugwise set device <mac> on | off
