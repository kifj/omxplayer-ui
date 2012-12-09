omxplayer-ui
============

omxplayer-ui is a mobile webclient and a PHP service to control omxplayer on Rasperry Pi

Prerequisites (this is my setup, many ways lead to Rome)
 * install Raspian (http://www.raspbian.org/RaspbianInstaller)
 * you need these packages:
 * omxplayer
 * php-cgi 
 * apache2-mpm-prefork (any webserver which can run PHP scripts will do, I'm used to apache2)
 * djmount (optional)

Setup for Nginx: add this location to the site config

    # nginx configuration
    location /omxplayer-ui/ {
      if (!-e $request_filename) {
        rewrite ^/omxplayer-ui/(.*)$ /omxplayer-ui/index.php;
      }
    }

Setup (this is my setup, many ways ... you know that already)
 * copy etc/omx_runner.sh to /usr/local/bin
 * djmount mounts the media servers to /media/upnp (in /etc/rc.local add /usr/bin/djmount -o allow_other,iocharset=UTF-8 /media/upnp > /dev/null)
 * if you have a different mount point or need different options for omxplayer, edit index.php
 * the first level of directories represents the servers, you can set up symlinks to samba or nfs mounts if you like
 * omxplayer-ui needs to be published at the webserver at /omxplayer-ui, or you edit the URL in index.php
 * the UI should work well on any modern (HTML5 ready) browser, I've tested with Chrome 22, Firefox 16 and Webkit on Android 2.2

Feature set:
 * on the "browse" page:
  * browse through the media servers content (folders)
  * play file
 * on the "control" page:
  * pause 
  * volume up and down
  * seek 30sec forward and backward
  * stop
  * show MP3 infos from tag
  * show what is currently played
 * search (which can be done in djmount with some special ls _search/ filters

Parts of the code were reused from https://github.com/JugglerLKR/omxplayer-web-controls-php, 
especially the trick how to send keys to a running omxplayer through a FIFO.

Open features:
 * a playlist (you can only play one file currently)
  * add and remove items to the playlist (stored in a file)  
  * show playlist on control page   
 * pause in the header bar
 * image viewer
