#!/bin/sh
FIFO=/tmp/omxplayer_fifo
( omxplayer -p -o hdmi "$1" < $FIFO ; rm "/tmp/omxplayer_current.txt" ) >/dev/null 2>&1 &
echo -n  > $FIFO
