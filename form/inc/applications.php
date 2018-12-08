<form>
    <input data-type="search" id="searchForCollapsibleSet">
</form>
<div data-role="collapsibleset" data-filter="true" data-inset="true" id="collapsiblesetForFilter" data-input="#searchForCollapsibleSet">
<div class="activeApps">
    <?
        $applications = getUserApplications($_SESSION['user_email']);
        $archived = [];
        foreach($applications as $id){
            $application = getApplication($id);
            if($application->status == 'archived'){
                array_push($archived, $application);
                continue;
            }
            printApplication($application);
        }
        echo '</div>';
        echo '<h3 class="ui-bar ui-bar-a ui-corner-all">Archiwum</h3>';
        echo '<div class="archivedApps">';
        foreach($archived as $application){
            printApplication($application);
        }
        echo '</div>';
    ?>
</div>
<script>
function archive(appId){
    $.post('/api/api.html', 
        {action: 'archive', id: appId}).done(function() {
            $('#' + appId).appendTo('.archivedApps');
        });
}
</script>