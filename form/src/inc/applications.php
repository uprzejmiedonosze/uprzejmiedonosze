<form>
    <input data-type="search" id="searchForCollapsibleSet">
</form>
<div data-role="collapsibleset" data-filter="true" data-mini="true" data-inset="true" id="collapsiblesetForFilter" data-input="#searchForCollapsibleSet">
<div class="activeApps"></div>
    <?
        $user = $storage->getCurrentUser();
        $applications = $user->getApplicationIds();
        $archived = [];
        foreach($applications as $id){
            $application = $storage->getApplication($id);
            if($application->status == 'archived'){
                array_push($archived, $application);
                continue;
            }
            $application->print(true);
        }
        echo '<h3 class="archivedApps ui-bar ui-bar-a ui-corner-all">Archiwum</h3>';
        foreach($archived as $application){
            $application->print(true);
        }
        echo '</div>';
    ?>

<script>
function action(action, appId){
    $.post('/api/api.html', 
        {action: action, id: appId}).done(function() {
            if(action == 'archived'){ // przenieś jeśli do archiwum
                $('.archivedApps').after($('#' + appId));
            }else{ // przenieś, jeśli wcześniej było w archiwum
                if($('#' + appId).hasClass('status-archived')){
                    $('.activeApps').after($('#' + appId));
                }
            }
            $('#' + appId).removeClass('status-active status-confirmed status-confirmed-waiting status-confirmed-waitingE status-confirmed-sm status-confirmed-ignored status-confirmed-fined status-archived');    
            $('#' + appId).addClass('status-' + action);

            $('#' + appId + ' a.ui-state-disabled').removeClass('ui-state-disabled');
            $('#' + appId + ' a.' + 'status-' + action).addClass('ui-state-disabled');

        });
}
</script>