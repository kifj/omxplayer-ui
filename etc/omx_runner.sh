#!/bin/sh
PLAY_FILE=$1
shift
OMXPLAYER_OPTIONS=$@
OMXPLAYER_CURRENT="../data/omxplayer_current.txt"
FIFO=/tmp/omxplayer_fifo
( omxplayer $OMXPLAYER_OPTIONS "$PLAY_FILE" < $FIFO ; rm "$OMXPLAYER_CURRENT" ) >/dev/null 2>&1 &
echo -n > $FIFO