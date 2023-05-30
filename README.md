# dowp.php
A PHP based script to convert a WordPress Archive to Day One posts.

Requires the images/attachments to be locally available.
Writes out the posts to an html file, before `cat`-ing the file into the `dayone2`-app with parameters for the date, attachments, tags, and coordinates if available.
