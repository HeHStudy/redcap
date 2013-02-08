/*
See http://webplayer.yahoo.com/docs/how-to-set-up/#customize
This file of customizations must come before  <script type="text/javascript" src="http://webplayer.yahooapis.com/player.js">
*/

/*
stop the player from advancing to the next track in the playlist.  
We do not want participants getting another sound track automatically; this could be confusing.

Aslo include a default gif in the player which overrides the play.gif, so as to avoid confusion.  We don't want users clicking a gif that does nothing.
*/
var YWPParams = 
{
    autoadvance: false,
    defaultalbumart: 'http://www.tsgc.utexas.edu/grants/graphics/blank.gif'
};