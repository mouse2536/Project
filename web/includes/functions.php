<?php
//session_start();
include_once 'psl-config.php';

function sec_session_start() {
    ob_start();
    $session_name = 'sec_session_id';   // Set a custom session name
    $secure = SECURE;
    // This stops JavaScript being able to access the session id.
    $httponly = true;
    // Forces sessions to only use cookies.
    if (ini_set('session.use_only_cookies', 1) === FALSE) {
        header("Location: ../error.php?err=Could not initiate a safe session (ini_set)");
        exit();
    }
    // Gets current cookies params.
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params($cookieParams["lifetime"],
        $cookieParams["path"], 
        $cookieParams["domain"], 
        $secure,
        $httponly);
    // Sets the session name to the one set above.
    session_name($session_name);
    session_start();            // Start the PHP session 
    session_regenerate_id();    // regenerated the session, delete the old one. 
    ob_end_flush();
}


function login($club_id, $password, $mysqli) {
    // Using prepared statements means that SQL injection is not possible. 
    if ($stmt = $mysqli->prepare("SELECT id, username, password, salt 
        FROM users
        WHERE id = ?
        LIMIT 1")) {
        $stmt->bind_param('s', $club_id);  
        $stmt->execute();   
        $stmt->store_result();

        $stmt->bind_result($user_id, $username, $db_password, $salt);
        $stmt->fetch();
        // hash the password with the unique salt.
        $password = hash('sha512', $password . $salt);
        if ($stmt->num_rows == 1) {
            // If the user exists we check if the account is locked
 			if (checkbrute($user_id, $mysqli) == true) {
                // Account is locked 
                return false;
            } else {
                // Check if the password matches the one in the database 
                if ($db_password == $password) {
                    // Password is correct!

                    $user_browser = $_SERVER['HTTP_USER_AGENT'];

                    $user_id = preg_replace("/[^0-9]+/", "", $user_id);
                    $_SESSION['user_id'] = $user_id;

                    $username = preg_replace("/[^a-zA-Z0-9_\-]+/", 
                                                                "", 
                                                                $username);
                    $_SESSION['username'] = $username;
                    $_SESSION['login_string'] = hash('sha512', 
                              $password . $user_browser);
                    // Login successful.

                    // store login date.
                    $date = date("Y-m-d");
                    mysqli_query($mysqli,"UPDATE users SET last_online = '$date'     
                    WHERE id = $user_id");

                    mysqli_close($mysqli);
                    return true;
                } else {
                    // Password is not correct
                    $now = time();
                    $mysqli->query("INSERT INTO login_attempts(user_id, time)
                                    VALUES ('$user_id', '$now')");
                    return false;
                }
            }
        } else {
            // No user exists.
            return false;
        }
    }
}


function login_check($mysqli) {
    // Check if all session variables are set 
    if (isset($_SESSION['user_id'], 
                        $_SESSION['username'], 
                        $_SESSION['login_string'])) {
 
        $user_id = $_SESSION['user_id'];
        $login_string = $_SESSION['login_string'];
        $username = $_SESSION['username'];
 
        // Get the user-agent string of the user.
        $user_browser = $_SERVER['HTTP_USER_AGENT'];
 
        if ($stmt = $mysqli->prepare("SELECT password 
                                      FROM users 
                                      WHERE id = ? LIMIT 1")) {
            // Bind "$user_id" to parameter. 
            $stmt->bind_param('i', $user_id);
            $stmt->execute();   // Execute the prepared query.
            $stmt->store_result();
 
            if ($stmt->num_rows == 1) {
                // If the user exists get variables from result.
                $stmt->bind_result($password);
                $stmt->fetch();
                $login_check = hash('sha512', $password . $user_browser);
 
                if ($login_check == $login_string) {
                    // Logged In!!!! 
                    return true;
                } else {
                    // Not logged in 
                    return false;
                }
            } else {
                // Not logged in 
                return false;
            }
        } else {
            // Not logged in 
            return false;
        }
    } else {
        // Not logged in 
        return false;
    }
}

function check_afsluttet($mysqli, $pid) {
    if(login_check($mysqli) == true) {
        if (project_enddate($mysqli, $pid) == "Ikke afsluttet endnu") {
            return true;
        }
        else {
            return false;
        }
    }
}

function check_overadmin($mysqli) {
    if(login_check($mysqli) == true) {
        $user_id = $_SESSION['user_id'];
        if ($stmt = $mysqli->prepare("SELECT admin 
                                      FROM users 
                                      WHERE id = $user_id LIMIT 1")) {
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($admin);
            $stmt->fetch();
            if($admin == "2") {
                return true;    
            }
            else {
                return false;}
        }
        else {
            return false;}
    }
    else {
        return false;}    
}

function check_admin($mysqli) {

    if(login_check($mysqli) == true) {
        $user_id = $_SESSION['user_id'];
        if ($stmt = $mysqli->prepare("SELECT admin 
                                      FROM users 
                                      WHERE id = $user_id LIMIT 1")) {
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($admin);
            $stmt->fetch();
            if($admin == "1" || $admin == "2") {
                return true;    
            }
            else {
                return false;}
        }
        else {
            return false;}
    }
    else {
        return false;}
}

function user_name($mysqli) {
    if(login_check($mysqli) == true) {
        $user_id = $_SESSION['user_id'];
        if ($stmt = $mysqli->prepare("SELECT username 
                                      FROM users 
                                      WHERE id = $user_id")) {
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($name);
            $stmt->fetch();

            return $name;
        }
    }
}

function esc_url($url) {
 
    if ('' == $url) {
        return $url;
    }
 
    $url = preg_replace('|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\\x80-\\xff]|i', '', $url);
 
    $strip = array('%0d', '%0a', '%0D', '%0A');
    $url = (string) $url;
 
    $count = 1;
    while ($count) {
        $url = str_replace($strip, '', $url, $count);
    }
 
    $url = str_replace(';//', '://', $url);
 
    $url = htmlentities($url);
 
    $url = str_replace('&amp;', '&#038;', $url);
    $url = str_replace("'", '&#039;', $url);
 
    if ($url[0] !== '/') {
        // We're only interested in relative links from $_SERVER['PHP_SELF']
        return '';
    } else {
        return $url;
    }
}

function checkbrute($user_id, $mysqli) {

    $now = time();
    $valid_attempts = $now - 600;
    if ($result = $mysqli->query("SELECT time 
                                 FROM login_attempts
                                 WHERE user_id = $user_id 
                                 AND time > $valid_attempts
                                 ORDER BY time")) {

    	$row_cnt = $result->num_rows;
        if ($row_cnt > 5) {
            return true;
        } else {
            return false;
        }
    }   
}

function euroDate($date){
    if($date != null){
    $dateTime = new DateTime($date);
    $formatted_date=date_format ( $dateTime, 'd-m-Y' );
    return $formatted_date;
    }
}

function show_projects($mysqli){
     if (empty($_REQUEST['order'])) {
        $order = 'null';
    }
    else{
        $order = $_REQUEST["order"];    
    }
    $direction = @$_GET['direction'];
     if (empty($_REQUEST['direction'])){
        $direction = 'ASC';
    } 
    if ($direction !== "DESC"){ 
        $direction = "ASC"; 
    }
    if ($direction == "ASC"){ 
        $direction_link = "DESC"; 
    }
    if (empty($direction_link)){
        $direction_link = 'ASC';
    } 
    $result = mysqli_query($mysqli,"SELECT project_id, project_name, category_name, start_date, end_date, username 
                                    FROM projekt, users, categories 
                                    WHERE users.id = projekt.creator_id AND projekt.category = categories.category_name
                                    ORDER BY $order $direction");

    echo"<table class='pure-table pure-table-striped'>
    <thead>
    <tr>
    <th><a href = 'all_projects.php?order=project_name&direction=$direction_link'>Projekt navn</a></th>
    <th><a href = 'all_projects.php?order=category_name&direction=$direction_link'>Kategori</a></th>
    <th><a href = 'all_projects.php?order=start_date&direction=$direction_link'>Start-dato</a></th>
    <th><a href = 'all_projects.php?order=end_date&direction=$direction_link'>Slut-dato</a></th>
    <th><a href = 'all_projects.php?order=username&direction=$direction_link'>Opretter</a></th>
    <th>Tilføj timer</th>
    </tr>
    </thead>";

    while($row = mysqli_fetch_array($result)) {
        echo "<tr>";
        echo "<td><a href = 'project_info.php?pid=$row[0]'> $row[1]</a> </td>";
        echo "<td>" . $row[2] . "</td>";
        echo "<td>" . ($row[3]) . "</td>";
        echo "<td>" . ($row[4]) . "</td>";
        echo "<td>" . $row[5] . "</td>";
        if (check_afsluttet($mysqli, $row[0]) == true) {
            echo "<td><a href = 'add_time.php?pid=$row[0]'>Tilføj</a> </td>";
        }
        else {
            echo "<td> Afsluttet </td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}

function show_my_projects($mysqli){
     if (empty($_REQUEST['order'])) {   
        $order = 'null';
    }
    else{
        $order = $_REQUEST["order"];    
    }
    $direction = @$_GET['direction'];
     if (empty($_REQUEST['direction'])){
        $direction = 'ASC';
    } 
    if ($direction !== "DESC"){ 
        $direction = "ASC"; 
    }
    if ($direction == "ASC"){ 
        $direction_link = "DESC"; 
    }
    if (empty($direction_link)){
        $direction_link = 'ASC';
    } 
    $userid = $_SESSION['user_id'];
    $SQL = "SELECT DISTINCT projekt.project_id, project_name, category_name, start_date, end_date, username 
            FROM projekt, users, categories, work_on 
            WHERE users.id = projekt.creator_id AND projekt.category = categories.category_name
            AND work_on.user_id = $userid AND work_on.project_id = projekt.project_id
            ORDER BY $order $direction";

    $result = mysqli_query($mysqli, $SQL);

    echo"<table class='pure-table pure-table-striped'>
    <thead>
    <tr>
    <th><a href = 'my_projects.php?order=project_id&direction=$direction_link'>Projekt navn</a></th>
    <th><a href = 'my_projects.php?order=category&direction=$direction_link'>Kategori</a></th>
    <th><a href = 'my_projects.php?order=start_date&direction=$direction_link'>Start-dato</a></th>
    <th><a href = 'my_projects.php?order=end_date&direction=$direction_link'>Slut-dato</a></th>
    <th><a href = 'my_projects.php?order=username&direction=$direction_link'>Opretter</a></th>
    <th>Tilføj timer</th>
    </tr>
    </thead>";

    while($row = mysqli_fetch_array($result)) {
        echo "<tr>";
        echo "<td><a href = 'project_info.php?pid=$row[0]'> $row[1]</a> </td>";
        echo "<td>" . $row[2] . "</td>";
        echo "<td>" . ($row[3]) . "</td>";
        echo "<td>" . ($row[4]) . "</td>";
        echo "<td>" . $row[5] . "</td>";
        if (check_afsluttet($mysqli, $row[0]) == true) {
            echo "<td><a href = 'add_time.php?pid=$row[0]'>Tilføj</a> </td>";
        }
        else {
            echo "<td> Afsluttet </td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}

function history($mysqli){
    if (empty($_REQUEST['order'])) {
        $order = 'null';
    }
    else{
        $order = $_REQUEST["order"];    
    }
    $direction = @$_GET['direction'];
     if (empty($_REQUEST['direction'])){
        $direction = 'ASC';
    } 
    if ($direction !== "DESC"){ 
        $direction = "ASC"; 
    }
    if ($direction == "ASC"){ 
        $direction_link = "DESC"; 
    }
    if (empty($direction_link)){
        $direction_link = 'ASC';
    } 

    $userid = $_SESSION['user_id'];
    $result = mysqli_query($mysqli,"SELECT work_on.date, project_name, category, hours, work_on_id, projekt.project_id
                                    FROM work_on, projekt 
                                    WHERE work_on.project_id = projekt.project_id AND work_on.user_id = $userid
                                    ORDER BY $order $direction");

    echo"<table class='pure-table pure-table-striped'>
    <thead>
    <tr>
    <th><a href = 'history.php?order=work_on.date&direction=$direction_link'> Dato</a></th>
    <th><a href = 'history.php?order=project_name&direction=$direction_link'>Projekt navn</a></th>
    <th><a href = 'history.php?order=category&direction=$direction_link'>Kategori</a></th>
    <th><a href = 'history.php?order=hours&direction=$direction_link'>Timer</a></th>
    <th>Rediger tid</th>
    </tr>
    </thead>";

    while($row = mysqli_fetch_array($result)) {
        echo "<tr>";
        echo "<td>" . $row[0] . "</td>";
        echo "<td><a href = 'project_info.php?pid=$row[5]'> $row[1]</a> </td>";
        echo "<td>" . ($row[2]) . "</td>";
        echo "<td>" . ($row[3]) . "</td>";
        if (check_afsluttet($mysqli, $row[5]) == true) {
            echo "<td><a href = 'change_history.php?wid=$row[4]'>Rediger</a> </td>";
        }
        else {
            echo "<td> Afsluttet </td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}

function change_history($mysqli, $wid){
    $userid = $_SESSION['user_id'];
    $result = mysqli_query($mysqli,"SELECT work_on.date, project_name, category, hours, work_on.info
                                    FROM work_on, projekt 
                                    WHERE work_on.project_id = projekt.project_id AND work_on.user_id = $userid
                                    AND work_on_id = $wid");

    echo"<table class='pure-table pure-table-striped'>
    <thead>
    <tr>
    <th>Dato</th>
    <th>Projekt navn</th>
    <th>Kategori</th>
    <th>Timer</th>
    <th>Info</th>
    </tr>
    </thead>";

    while($row = mysqli_fetch_array($result)) {
        echo "<tr>";
        echo "<td>" . $row[0] . "</td>";
        echo "<td>" . $row[1] . "</td>";
        echo "<td>" . ($row[2]) . "</td>";
        echo "<td>" . ($row[3]) . "</td>";
        echo "<td>" . $row[4] . "</td>";
        echo "</tr>";
    }
    echo "</table>";    
}


function chooseCategory($mysqli){
    $sql = "SELECT category_name FROM categories";
    $result = mysqli_query($mysqli,$sql);
    echo "<select name=\"category\">";

    while($row = mysqli_fetch_array($result)) {
        echo "<option value=\"" . $row[0] . "\">" . $row[0] . "</option>";
    }
    echo "</select>";
}


function project_name($mysqli, $pid) {
    if(login_check($mysqli) == true) {
        if ($stmt = $mysqli->prepare("SELECT project_name 
                                      FROM projekt 
                                      WHERE project_id = $pid")) {
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($name);
            $stmt->fetch();

            return $name;
        }
    }
}

function project_info($mysqli, $pid) {
    if(login_check($mysqli) == true) {
        if ($stmt = $mysqli->prepare("SELECT info
                                      FROM projekt 
                                      WHERE project_id = $pid")) {
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($name);
            $stmt->fetch();

            return $name;
        }
    }
}

function project_startdate  ($mysqli, $pid) {
    if(login_check($mysqli) == true) {
        if ($stmt = $mysqli->prepare("SELECT start_date
                                      FROM projekt 
                                      WHERE project_id = $pid")) {
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($date);
            $stmt->fetch();

            return $date;
        }
    }
}

function project_enddate($mysqli, $pid) {
    if(login_check($mysqli) == true) {
        if ($stmt = $mysqli->prepare("SELECT end_date
                                      FROM projekt 
                                      WHERE project_id = $pid")) {
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($date);
            $stmt->fetch();

            if($date == NULL) {
                return "Ikke afsluttet endnu";
            }
            return $date;
        }
    }
}

function close_projekt($mysqli, $pid) {
    if(login_check($mysqli) == true) {
        $date = date("Y-m-d");
        mysqli_query($mysqli,"UPDATE projekt SET end_date = '$date'     
        WHERE project_id = $pid");

        mysqli_close($mysqli);
    }
}

function open_projekt($mysqli, $pid) {
    if(login_check($mysqli) == true) {
        mysqli_query($mysqli,"UPDATE projekt SET end_date = NULL    
        WHERE project_id = $pid");

        mysqli_close($mysqli);
    }
}

function project_history($mysqli, $pid){
    $result = mysqli_query($mysqli,"SELECT username, work_on.date, hours, work_on.info
                                    FROM work_on, users
                                    WHERE work_on.user_id = users.id AND project_id = $pid");

    echo"<table class='pure-table pure-table-striped'>
    <thead>
    <tr>
    <th>Navn</th>
    <th>Dato</th>
    <th>Timer</th>
    <th>Info</th>
    </tr>
    </thead>";

    while($row = mysqli_fetch_array($result)) {
        echo "<tr>";
        echo "<td>" . $row[0] . "</td>";
        echo "<td>" . $row[1] . "</td>";
        echo "<td>" . ($row[2]) . "</td>";
        echo "<td>" . ($row[3]) . "</td>";
        echo "</tr>";
    }
    echo "</table>";    
}

function projekt_timer_sum($mysqli, $pid) {
    if(login_check($mysqli) == true) {
        $res = mysqli_query($mysqli,"SELECT sum(hours) FROM work_on WHERE project_id = '$pid'");
        $row = mysqli_fetch_row($res);
        $sum = $row[0];
        if($sum < 1)
            return 0;
        else {
            return $sum;
        }
    }
}

function project_category($mysqli, $pid) {
    if(login_check($mysqli) == true) {
        if ($stmt = $mysqli->prepare("SELECT category
                                      FROM projekt 
                                      WHERE project_id = $pid")) {
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($category);
            $stmt->fetch();

            return $category;
        }
    }
}

function sidst_online($mysqli, $uid) {
    if(login_check($mysqli) == true) {
        if ($stmt = $mysqli->prepare("SELECT last_online
                                      FROM users
                                      WHERE id = $uid")) {
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($category);
            $stmt->fetch();

            return $category;
        }
    }
}

function user_table($mysqli){

    $result = mysqli_query($mysqli,"SELECT username, id, admin
                                    FROM users
                                    WHERE admin < 2");

    echo"<table class='pure-table pure-table-striped'>
    <thead>
    <tr>
    <th>Navn</th>
    <th>Id</th>
    <th>Status</th>
    <th>password</th>
    <th>Lav til admin</th>
    <th>Lav til bruger</th>
    <th>SidstOnline</th>
    </tr>
    </thead>";

    while($row = mysqli_fetch_array($result)) {
        echo "<tr>";
        echo "<td>" . $row[0] . "</td>";
        echo "<td>" . $row[1] . "</td>";
        if ($row[2] == 1) {
            echo "<td>Administrator</td>";
        }
        else {
            echo"<td>Bruger</td>";
        }
        echo "<td><a href = 'get_password.php?uid=$row[1]'>Gendan</a></td>";
        if ($row[2] == 0) {
            echo "<td><a href = 'promote.php?uid=$row[1]'>Promote</a></td>";
        }
        else {
            echo "<td></td>";
        }
        if ($row[2] == 1) {
            echo "<td><a href = 'demote.php?uid=$row[1]'>Demote</a></td>";
        }
        else {
            echo "<td></td>";
        }
        echo "<td>" . sidst_online($mysqli, $row[1]) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}   

function get_userState($mysqli, $uid) {
    if(login_check($mysqli) == true) {
        if ($stmt = $mysqli->prepare("SELECT admin
                                      FROM users 
                                      WHERE id = $uid")) {
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($id);
            $stmt->fetch();

            return $id;
        }
    }
}

function get_mail($mysqli) {
    if ($stmt = $mysqli->prepare("SELECT name
                                  FROM email 
                                  WHERE id = 1")) {
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($name);
        $stmt->fetch();

        return $name;
    }
}   

function update_email($mysqli, $newEmail) {
    
    mysqli_query($mysqli,"UPDATE email SET name = '$newEmail'     
                          WHERE id = 1");

    mysqli_close($mysqli);
}

function get_username($mysqli, $uid) {
    if ($stmt = $mysqli->prepare("SELECT username 
                                  FROM users 
                                  WHERE id = $uid")) {
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($name);
        $stmt->fetch();
        return $name;
    }
}
