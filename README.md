My Courses
==========

What is this?
-------------

The my_courses block for Moodle was originally a fork of Moodle's builtin course_overview block,
with the intention of showing DSV students more information about upcoming and passed courses in
Moodle. The block currently basically does two things in addition to what the course_overview block
does:

 - Divides user courses into _Teaching_ and _Studying_ sections
 - Shows which courses are upcoming, ongoing, and finished


Usage
-----

Place the block where you wish within your Moodle instance, the rest will happen automagically :)


Settings
--------

The settings for this block can be found under "Site configuration" -> "Plugins" -> "Blocks" -> 
"My courses". The following settings are there:

<h4>API URL, API user, and API key</h4>
These are all pretty straight-forward. First is the REST API URL to try to connect to, the other two
are the credentials to use for the connection.

<h4>Default maximum courses</h4>
This setting determines how many courses to display at most

<h4>Force maximum courses</h4>
Force the above (un-overridable)

<h4>Show children</h4>
Toggles the view of children courses

<h4>Show welcome area</h4>
Shows a small welcome area at the top of the block, with unread messages count, the user's image
etc. etc.