#!/bin/sh
PLAY_FILE=$1
shift
OMXPLAYER_OPTIONS=$@
DIR=$(dirname $0)
OMXPLAYER_CURRENT="$DIR/../data/omxplayer_current.txt"
OMXPLAYER_PLAYLIST="$DIR/../data/omxplayer_playlist.m3u"
OMXPLAYER_HOSTNAME=$(hostname -f)
OMXPLAYER_WATCHDOG="curl -H \"Content-Type: application/json\" http://$OMXPLAYER_HOSTNAME/omxplayer-ui/watchdog"

if [ "${PLAY_FILE##*.}" = "m3u" ]; then
	PLAY_LIST=$(grep -v '^#.*$' $PLAY_FILE)
	PLAY_FILE=$(echo $PLAY_LIST | head -1)
	PLAY_LIST=$(echo $PLAY_LIST | tail -n +2)
	if [ -n "$PLAY_LIST" ]; then
		echo "$PLAY_LIST" > OMXPLAYER_PLAYLIST
	fi
fi

if [ -z "$PLAY_FILE" ]; then
	exit
fi

FIFO=/tmp/omxplayer_fifo
( omxplayer $OMXPLAYER_OPTIONS "$PLAY_FILE" < $FIFO ; rm "$OMXPLAYER_CURRENT" ; $OMXPLAYER_WATCHDOG ) >/dev/null 2>&1 &
echo -n > $FIFO
