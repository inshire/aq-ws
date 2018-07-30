#!/bin/bash
if [ "$1" = "" -o "$2" = "" ];then
	echo "Usage:sh $0 file {start|stop|restart}"
	exit
fi
phppath="/usr/local/php-7.0.2/bin/php"
filepath="/data/server/aq-ws/"
filename="$filepath$1"
if [ ! -f $filename ];then
	echo "$filename is not found!" 
	exit
fi

# start
start() {
	process_num=$(ps -ef|grep $filename|grep -v grep|wc -l)
	if [ $process_num != "0" ];then
		echo "$filename is running!"
		exit
	else
		echo "starting $filename  ..."
		$($phppath $filename)
		echo "Success!"
	fi
}

# stop
stop() {
	process_num=$(ps -ef|grep $filename|grep -v grep|wc -l)
	if [ $process_num != "0" ];then
		echo "stopping $filename ..."
		ps -ef|grep $filename|grep -v grep|cut -c 9-15|xargs kill -9
		echo "Stopped!"
	else
		echo "$filename is not running!"
	fi
}
case "$2" in
start)
start
;;
stop)
stop
;;
restart)
stop
start
;;
*)
echo "Usage:sh $0 $1 {start|stop|restart}"
exit
esac

