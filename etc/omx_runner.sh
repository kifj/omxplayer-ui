#!/bin/sh
PLAY_FILE=$1
shift
OMXPLAYER_OPTIONS=$@;
FIFO=/tmp/omxplayer_fifo
( omxplayer $OMXPLAYER_OPTIONS "$PLAY_FILE" < $FIFO ; rm "/tmp/omxplayer_current.txt" ) >/dev/null 2>&1 &
echo -n  > $FIFO
