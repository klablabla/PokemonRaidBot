<?php
// Write to log.
debug_log('RAID_FROM_WEBHOOK()');

foreach ($update as $raid) {

    $level = $raid['message']['level'];
    $pokemon = $raid['message']['pokemon_id'];
    $exclude_raid_levels = explode(',', WEBHOOK_EXCLUDE_RAID_LEVEL);
    $exclude_pokemons = explode(',', WEBHOOK_EXCLUDE_POKEMON);
    if ((!empty($level) && in_array($level, $exclude_raid_levels)) || (!empty($pokemon) && in_array($pokemon, $exclude_pokemons))) {
        
        continue;
    }

    // Create gym if not exists
    $gym_name = $raid['message']['name'];
    $gym_lat = $raid['message']['latitude'];
    $gym_lon = $raid['message']['longitude'];
    $gym_id = $raid['message']['gym_id'];
    $gym_img_url = $raid['message']['url'];
    $gym_is_ex = $raid['message']['is_ex_raid_eligible'];
    $gym_internal_id = 0;
    
    // Does gym exists?
    $gym_lat_exist = 0;
    $gym_lot_exist = 0;
    $gym_name_exist = '';
    $gym_is_ex_exist = 0;
    $gym_img_url_exist = '';
    try {

        $query = '
            SELECT id, lat, lon, gym_name, ex_gym, img_url
            FROM gyms
            WHERE
                gym_id LIKE :gym_id
            LIMIT 1
        ';
        $statement = $dbh->prepare( $query );
        $statement->bindValue(':gym_id', $gym_id, PDO::PARAM_STR);
        $statement->execute();
        while ($row = $statement->fetch()) {

            $gym_internal_id = $row['id'];            
            $gym_lat_exist = $row['lat'];
            $gym_lon_exist = $row['lon'];
            $gym_name_exist = $row['gym_name'];
            $gym_is_ex_exist = $row['ex_gym'];
            $gym_img_url_exist = $row['img_url'];
        }
    }
    catch (PDOException $exception) {

        error_log($exception->getMessage());
        $dbh = null;
        exit;
    }
    // Update gym name in raid table.
    if ($gym_internal_id > 0) {
        
        try {

            $query = '
                UPDATE gyms
                SET
                    lat = :lat,
                    lon = :lon,
                    gym_name = :gym_name,
                    ex_gym = :ex_gym,
                    img_url = :img_url,
                    show_gym = 1
                WHERE
                    gym_id LIKE :gym_id
            ';
            $statement = $dbh->prepare( $query );
            $statement->bindValue(':lat', $gym_lat_exist, PDO::PARAM_STR);
            $statement->bindValue(':lon', $gym_lon_exist, PDO::PARAM_STR);
            $statement->bindValue(':gym_name', $gym_name_exist, PDO::PARAM_STR);
            $statement->bindValue(':ex_gym', $gym_is_ex_exist, PDO::PARAM_INT);
            $statement->bindValue(':img_url', $gym_img_url_exist, PDO::PARAM_STR);
            $statement->bindValue(':gym_id', $gym_internal_id, PDO::PARAM_STR);
            $statement->execute();
        }
        catch (PDOException $exception) {

            error_log($exception->getMessage());
            $dbh = null;
            exit;
        }
    }
    // Create gym
    else {
        
        try {

            $query = '
                
                INSERT INTO gyms (lat, lon, gym_name, gym_id, ex_gym, img_url, show_gym)
                VALUES (:lat, :lon, :gym_name, :gym_id, :ex_gym, :img_url, 1)
            ';
            $statement = $dbh->prepare( $query );
            $statement->bindValue(':lat', $gym_lat, PDO::PARAM_STR);
            $statement->bindValue(':lon', $gym_lon, PDO::PARAM_STR);
            $statement->bindValue(':gym_name', $gym_name, PDO::PARAM_STR);
            $statement->bindValue(':gym_id', $gym_id, PDO::PARAM_STR);
            $statement->bindValue(':ex_gym', $gym_is_ex, PDO::PARAM_INT);
            $statement->bindValue(':img_url', $gym_img_url, PDO::PARAM_STR);
            $statement->execute();
            $gym_internal_id = $dbh->lastInsertId();
        }
        catch (PDOException $exception) {

            error_log($exception->getMessage());
            $dbh = null;
            exit;
        }
    }

    // Create raid if not exists otherwise update if changes are detected
    // Just an egg
    if ($pokemon == 0) {
        $pokemon = '999' . $level;
    }
        
    // TODO: Translate Form
    $form = 0;
    if ( isset($raid['message']['form']) ) {}
    $gender = 0;
    if ( isset($raid['message']['gender']) ) {
        
        $gender = $raid['message']['gender'];
    }
    $move_1 = 0;
    $move_2 = 0;
    if ($pokemon < 9900) {
     
       $move_1 = $raid['message']['move_1'];
       $move_2 = $raid['message']['move_2'];   
    }
    $pokemon = $pokemon . '-normal';
    $start_timestamp = $raid['message']['start'];
    $end_timestamp = $raid['message']['end'];
    $start = gmdate("Y-m-d H:i:s",$start_timestamp);
    $end = gmdate("Y-m-d H:i:s",$end_timestamp);
    $team = $raid['message']['team_id'];
    if (! empty($team)) {
        switch ($team) {
            case (1):
                $team = 'mystic';
                break;
            case (2):
                $team = 'valor';
                break;
            case (3):
                $team = 'instinct';
                break;
        }
    }

    // Insert new raid or update existing raid/ex-raid?
    $raid_id = active_raid_duplication_check($gym_internal_id);
    
    // Raid exists, do updates!
    if ( $raid_id > 0 ) {
        
        try {

            $query = '
                UPDATE raids
                SET
                    pokemon = :pokemon,
                    gym_team = :gym_team,
                    move1 = :move1,
                    move2 = :move2,
                    gender = :gender
                WHERE
                    id LIKE :id
            ';
            $statement = $dbh->prepare( $query );
            $statement->bindValue(':pokemon', $pokemon, PDO::PARAM_STR);
            $statement->bindValue(':gym_team', $team, PDO::PARAM_STR);
            $statement->bindValue(':move1', $move_1, PDO::PARAM_STR);
            $statement->bindValue(':move2', $move_2, PDO::PARAM_STR);
            $statement->bindValue(':gender', $gender, PDO::PARAM_STR);
            $statement->bindValue(':id', $raid_id, PDO::PARAM_INT);
            $statement->execute();
			
			
			// Get raid info for updating 
			$raid_info = get_raid($raid_id);
			
			$updated_msg = show_raid_poll($raid_info);
			$updated_keys = keys_vote($raid_info);
			
			$cleanup_query = ' 
				SELECT    *
				FROM      cleanup
					WHERE   raid_id = :id
			';
            $cleanup_statement = $dbh->prepare( $cleanup_query );
            $cleanup_statement->bindValue(':id', $raid_id, PDO::PARAM_STR);
			$cleanup_statement->execute();
			
			while ($row = $cleanup_statement->fetch()) {
				$url = RAID_PICTURE_URL."?gym=".$raid_info['gym_id']."&pokemon=".$raid_info['pokemon']."&raid=".$raid_id;
				editMessageMedia($row['message_id'], $updated_msg, $updated_keys, $row['chat_id'], ['disable_web_page_preview' => 'true'],false, $url);
			}
        }
        catch (PDOException $exception) {

            error_log($exception->getMessage());
            $dbh = null;
            exit;
        }
//        send_response_vote($update, $data);
        continue;
    }
    
    // Create Raid and send messages
    try {

        $query = '
                
            INSERT INTO raids (pokemon, user_id, first_seen, start_time, end_time, gym_team, gym_id, move1, move2, gender)
            VALUES (:pokemon, :user_id, :first_seen, :start_time, :end_time, :gym_team, :gym_id, :move1, :move2, :gender)
        ';
        $statement = $dbh->prepare( $query );
        $statement->bindValue(':pokemon', $pokemon, PDO::PARAM_STR);
        $statement->bindValue(':user_id', WEBHOOK_CREATOR, PDO::PARAM_STR);
        $statement->bindValue(':first_seen', gmdate("Y-m-d H:i:s"), PDO::PARAM_STR);
        $statement->bindValue(':start_time', $start, PDO::PARAM_STR);
        $statement->bindValue(':end_time', $end, PDO::PARAM_STR);
        $statement->bindValue(':gym_team', $team, PDO::PARAM_STR);
        $statement->bindValue(':gym_id', $gym_internal_id, PDO::PARAM_INT);
        $statement->bindValue(':move1', $move_1, PDO::PARAM_STR);
        $statement->bindValue(':move2', $move_2, PDO::PARAM_STR);
        $statement->bindValue(':gender', $gender, PDO::PARAM_STR);
        $statement->execute();
        $raid_id = $dbh->lastInsertId();
    }
    catch (PDOException $exception) {

        error_log($exception->getMessage());
        $dbh = null;
        exit;
    }
    
    // Get raid data.
    $created_raid = get_raid($raid_id);

    // Set text.
    $text = show_raid_poll($created_raid);
    
    // Set keys.
    $keys = keys_vote($created_raid);

    // Get chats
    $chats = explode(',', WEBHOOK_CHATS);
    for($i = 1; $i <= 5; $i++) {
        $const = 'WEBHOOK_CHATS_LEVEL_' . $i;
        $const_chats = constant($const);

        // Debug.
        //debug_log($const,'CONSTANT NAME:');
        //debug_log($const_chats),'CONSTANT VALUE:');

        if($level == $i && defined($const) && !empty($const) && !empty($const_chats)) {
            $chats = explode(',', $const_chats);
        }
    }

    // Post raid polls.
    foreach ($chats as $chat) {
    
        // Send location.
        if (RAID_LOCATION == true) {

            $msg_text = !empty($created_raid['address']) ? $created_raid['address'] . ', ' . substr(strtoupper(BOT_ID), 0, 1) . '-ID = ' . $created_raid['id'] : $created_raid['pokemon'] . ', ' . substr(strtoupper(BOT_ID), 0, 1) . '-ID = ' . $created_raid['id']; // DO NOT REMOVE " ID = " --> NEEDED FOR CLEANUP PREPARATION!
            $loc = send_venue($chat, $created_raid['lat'], $created_raid['lon'], "", $msg_text);

            // Write to log.
            debug_log('location:');
            debug_log($loc);
        }
    
        // Set reply to.
        $reply_to = $chat; //$update['message']['chat']['id'];
    
        // Send the message.
        send_message($chat, $text, $keys, ['reply_to_message_id' => $reply_to, 'reply_markup' => ['selective' => true, 'one_time_keyboard' => true], 'disable_web_page_preview' => 'true']);
    }
}
?>
