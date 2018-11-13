<form>
    <input data-type="search" id="searchForCollapsibleSet">
</form>
<div data-role="collapsibleset" data-filter="true" data-inset="true" id="collapsiblesetForFilter" data-input="#searchForCollapsibleSet">
    <?
        $applications = array_reverse(getUserApplications($_SESSION['user_email']), true);
        foreach($applications as $id){
            $application = getApplication($id);
            $app_date = date_format(new DateTime($application->date), 'Y-m-d');
            $app_hour = date_format(new DateTime($application->date), 'H:i');
            $category = $categories_txt[$application->category];
            echo <<<HTML
       
       <div data-role="collapsible" data-filtertext="{$application->address->address} $application->number
            $application->date {$application->carInfo->plateId} {$application->userComment} $category">
            <h3>$application->number z dnia $app_date ({$application->address->address})</h3>
            <p data-role="listview" data-filtertext="Animals Cats" data-inset="false">
                    <p>W dniu <b>$app_date</b> roku o godzinie <b>$app_hour</b> byłem/am świadkiem pozostawienia
                        samochodu o nr rejestracyjnym <b>{$application->carInfo->plateId}</b> pod adresem <b>{$application->address->address}</b>.
                        $category Sytuacja jest widoczna na załączonych zdjęciach.</p>
                    <p>{$application->userComment}</p>
                    <div id="#pics" class="ui-grid-a ui-responsive">
                        <div class="ui-block-a">
                            <img class="lazyload" data-src="{$application->contextImage->thumb}"> 
                        </div>
                        <div class="ui-block-b">
                            <img class="lazyload" data-src="{$application->carImage->thumb}">
                        </div>
                    </div>

                <a href="/zgloszenie.html?id=$id">szczegóły</a>
                <a href="/api/download.html?appId=$id" target="_blank" data-ajax="false">pdf</a>
            </p>
        </div>       
HTML;
            
        }
    ?>
</div>
