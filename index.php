<?php

// connects to the database of a given wiki
function dbconnect( $database ) {
        // connect
        $ts_pw = posix_getpwuid( posix_getuid() );
        $mycnf = parse_ini_file( $ts_pw['dir'] . "/replica.my.cnf" );
        $username = $mycnf['user'];
        $password = $mycnf['password'];
        unset( $mycnf );
        $con = mysql_connect( $database . '.labsdb', $username, $password )
                or print '<p class="fail"><strong>Database server login failed.</strong> '
                         . ' This is probably a temporary problem with the server and will be fixed soon. '
                         . ' The server returned: ' . mysql_error() . '</p>';
        unset( $username );
        unset( $password );

        // select database
        if ( $con ) {
                mysql_select_db( $database . '_p' )
                        or print '<p class="fail"><strong>Database connection failed: '
                                 . mysql_error() . '</strong></p>';
        }
        return $con;
 }

 // compresses css code
 function compressor( $css ) {
		global $_GET;
		if ( $_GET['format'] == "cssmarker" && $_GET['bold'] === '0' )
			$css = str_replace( "font-weight: bold", "font-weight: normal", $css );
		if ( $_GET['format'] == "cssmarker" && ( !array_key_exists( 'nocompress', $_GET ) || !$_GET['nocompress'] ) ) {
		return preg_replace( "!/\*.*?\*/!", "", str_replace( array( "{ ", "; }", "; ", ": ", "\n", "\t", "  " ), array( "{", "}", ";", ":" ), $css ) );
		} else {
		return $css;
		}
 }

// a list of all avaible output formats
$avaibleFormats = array( "jsarenc", "wikilist", "cssmarker" );

// check input strings

// check if the output format is avaible, if not die
if ( !in_array( $_GET['format'], $avaibleFormats ) ) die( "no valid output format" );
// check if the query is ok, if not die
if ( !preg_match( "/^([-_a-zA-Z]+@[_a-z]*(wiki|wikiversity|wiktionary|wikiqoute|wikisource|wikinews|wikivoyage|wikimedia)(@[-_a-zA-Z+]+)?\\|?)+$/", $_GET['query'] ) ) die( "query not valid" );
// if cssmarker-output format is choosen, check wheter it is given and valid, if not die
if ( !preg_match( "/\\w/", $_GET['localuser'] ) && ( $_GET['format'] == "cssmarker" ) ) die( "invalid localised username" );
// if no caching time in minutes is given, three days are used (60*24*3)
if ( !array_key_exists( 'cache', $_GET ) || !$_GET['cache'] ) $cache = 60 * 60 * 24 * 3; else $cache = $_GET['cache'];
// check if the cache format is ok, if not die
if ( !preg_match( "/^[0-9]+$/", $cache ) ) die( "invalid cache format" );

$queryhash = md5( $_GET['format'] . "-" . $_GET['localuser'] . "-" . $_GET['query'] );

switch ( $_GET['format'] ) {
        case "wikilist":
                header( 'Content-type: text/plain' );
                break;
        case "jsarenc":
                header( 'Content-type: text/javascript' );
                break;
        case "cssmarker":
                header( 'Content-type: text/css' );
                break;
}

if ( $cache < 60 * 60 * 24 ) {
	header( "Expires: " . gmdate( "D, d M Y H:i:s", time() + $cache ) . " GMT" );
	header( "Cache-Control: max-age=" . $cache . " GMT" );
} else {
	header( "Expires: " . gmdate( "D, d M Y H:i:s", time() + ( 60 * 60 * 24 ) ) . " GMT" );
	header( "Cache-Control: max-age=" . ( 60 * 60 * 24 ) . " GMT" );
}

if ( file_exists( "cache/$queryhash" ) ) {
        if ( time() - filemtime( "cache/$queryhash" ) <= $cache ) { echo compressor( file_get_contents( "cache/$queryhash" ) ); die( 0 ); }
}

// seperate the different queries
$querys = explode( "|", $_GET['query'] );

// Initialize output.
$output = '';

// for each query do
for ( $h = 0; $h < sizeof( $querys ); ++$h ) {
        // separate the different parts of the query (group, project, marker (optional))
        $query = explode( "@", preg_replace( "/\\|$/", "", $querys[$h] ) );

        // for writing things easier
        $group = $query[0];
        $wiki = $query[1];
        if ( !ctype_alpha( $wiki ) ) die( "Bad wiki name" );
        if ( !ctype_alpha( $group ) ) die( "Bad group name" );

        $marker[$wiki . "-" . $group] = $query[2];

        // create an empty array for users
        $users = array();

        // connect to the appropiate database
        $con = dbconnect( $wiki );
        $result = mysql_query( 'SELECT user_name from user left join user_groups on user_id=ug_user where ug_group = \'' . $group . '\';' );
        while ( $row = mysql_fetch_array( $result, MYSQL_NUM ) ) {
                $users[] = $row[0];
                $usersAIO[$row[0]][] = $wiki . "-" . $group;
        }
        mysql_close( $con );
        $marker[$wiki . "-" . $group] = $query[2];
                $userCount = sizeof( $users );

        sort( $users );

        if ( $_GET['format'] == "wikilist" ) {

                $output .= ";${group}s on ${wiki}:\n";
                for ( $i = 0; $i < $userCount; ++$i ) {
                        $output .= ":" . $users[$i] . "\n";
                }
        }

        if ( $_GET['format'] == "jsarenc" ) {
                $output .= "${wiki}-${group} = new Array(";
                for ( $i = 0; $i < $userCount; ++$i ) {
                        $output .= "\"" . str_replace( '%21', '!', urlencode( str_replace( " ", "_", $users[$i] ) ) ) . "\""; if ( $i != $userCount - 1 ) $output .= ", ";
                }
                $output .= ");\n\n";
        }

}
if ( $_GET['format'] == "cssmarker" ) {
        for ( $i = 0; $i < sizeof( $querys ); ++$i ) {
                $usernamesPrinted = false;
                $printText = false;
                $query = explode( "@", preg_replace( "/\\|$/", "", $querys[$i] ) );
                $group = $query[0];
                $wiki = $query[1];
                $output .= "\n/* Mark ${group}s on $wiki with a bold " . $marker[$wiki . "-" . $group] . " */";
                foreach ( $usersAIO as $username => $usergroups ) {
                        if ( in_array( $wiki . "-" . $group, $usergroups ) && sizeof( $usergroups ) == 1 )
                                $printText .= "\na[href$=\"" . $_GET['localuser'] . ":" . str_replace( '%21', '!', urlencode( str_replace( " ", "_", $username ) ) ) . "\"]:after,";
                }
                $output .= substr_replace( $printText, "", -1 );
                if ( $printText ) $output .= "\n{ content:\" (" . $marker[$wiki . "-" . $group] . ")\"attr(id); font-weight: bold; }";
        }
        # BUGGY:
        foreach ( $usersAIO as $username => $usergroups ) {
                if ( sizeof( $usergroups ) >= 2 ) {
                        for ( $i = 0; $i < sizeof( $usergroups ); ++$i ) {
                                $groups .= $marker[$usergroups[$i]]; if ( $i < sizeof( $usergroups ) - 1 ) $groups .= "/";
                        }
                $userMultipleGroups[$groups][] = $username;
                }
        }
        foreach ( $usersAIO as $username => $usergroups ) {
                if ( sizeof( $usergroups ) >= 2 ) {
                        if ( !$moreGroups ) $moreGroups = $output .= "\n/* Mark users with membership in more than one group */";
                        $output .= "\na[href$=\"" . $_GET['localuser'] . ":" . str_replace( '%21', '!', urlencode( str_replace( " ", "_", $username ) ) ) . "\"]:after\n{ content:\" (";
                        for ( $i = 0; $i < sizeof( $usergroups ); ++$i ) {
                                $output .= $marker[$usergroups[$i]]; if ( $i < sizeof( $usergroups ) - 1 ) $output .= "/";
                        }
                        $output .= ")\"attr(id); font-weight: bold; }";
                }

        }
}

file_put_contents( "cache/$queryhash", $output );

print compressor( $output );

?>
