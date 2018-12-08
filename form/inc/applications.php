<form>
    <input data-type="search" id="searchForCollapsibleSet">
</form>
<div data-role="collapsibleset" data-filter="true" data-inset="true" id="collapsiblesetForFilter" data-input="#searchForCollapsibleSet">
    <?
        $applications = getUserApplications($_SESSION['user_email']);

        $archived = [];
        foreach($applications as $id){
            $application = getApplication($id);
            if($application->status == 'archived'){
                array_push($archived, $application);
                continue;
            }
            printApplication($application, true);
        }
        echo '<h3 class="ui-bar ui-bar-a ui-corner-all">Archiwum</h3>';

        foreach($archived as $application){
            printApplication($application, false);
        }
    ?>
</div>
<script>
function archive(appId){
    $.post('/api/api.html', 
        {action: 'archive', id: appId},
        function (data) {
            $('#' + appId).hide();
        });
}
</script>