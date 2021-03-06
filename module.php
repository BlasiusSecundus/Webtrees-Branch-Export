<?php
/**
 * Branch Export Webtrees Module
 */

namespace BlasiusSecundus\WebtreesModules\BranchExport;

use Composer\Autoload\ClassLoader;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Filter;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Controller\PageController;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Functions\FunctionsPrint;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Database;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Note;
use Fisharebest\Webtrees\Repository;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Tree;

require_once 'branchgenerator.php';
require_once 'branchexportutils.php';

define("BRANCH_EXPORT_MODULE_VERSION","1.2.0 DEV - xx.xx.2018");
define("BRANCH_EXPORT_MODULE_DB_VERSION",1);

/*
 
 reset to v0 from v1 (for testing purposes): 

ALTER TABLE `wt_branch_export_presets` CHANGE `tree_id` `l_file` INT(11) NOT NULL;
ALTER TABLE `wt_branch_export_presets` DROP `preset_id`;
ALTER TABLE wt_branch_export_presets DROP INDEX name;
DELETE FROM `wt_module_setting` WHERE `module_name` = 'branch_export' AND `setting_name` = 'current_db_version';
ALTER TABLE `wt_branch_export_presets` ADD PRIMARY KEY(`name`);
 
 
 */

/*
 CREATE TABLE IF NOT EXISTS `wt_branch_export_presets` (
  `preset_id` int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `tree_id` int(11) NOT NULL,
  `pivot` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `cutoff` varchar(300) COLLATE utf8_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
 * 
 */


//ALTER TABLE `wt_branch_export_presets` CHANGE `l_file` `tree_id` INT(11) NOT NULL;
//ALTER TABLE `wt_branch_export_presets` DROP PRIMARY KEY
//ALTER TABLE `wt_branch_export_presets` ADD `preset_id` INT NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (`preset_id`) ;

//ALTER TABLE `wt_branch_export_presets` ADD UNIQUE( `name`, `tree_id`);

class BranchExportModule extends AbstractModule implements ModuleMenuInterface, ModuleTabInterface, ModuleConfigInterface
{
    /**
     *
     * @var string The XREF of the pivot individual. 
     */
    var $PivotIndiXref = null;
    /**
     *
     * @var Individual The pivot individual (where the branch starts). 
     */
    var $PivotIndi = null;
    
    /**
     *
     * @var string[] XREFs of records that serve as cutoff points. 
     */
    var $CutoffXrefs = array();
    
    /**
     *
     * @var string The ID of the currently selected preset, or "NULL" if no preset is selected.
     */
    var $SelectedPreset = null;
     /**
     *
     * @var stdClass The selected preset object (or NULL).
     */
    var $SelectedPresetObj = null;
    
    /**
     *
     * @var stdClass[] The list of presets. 
     */
    var $Presets = array();
    
    /**
     *
     * @var BranchGenerator The branch generator.   
     */
    var $BranchGenerator = NULL;
    
    
    /**
     *
     * @var string 
     */
    var $TableNameWithoutPrefix = "branch_export_presets";
    
    /**
     *
     * @var boolean 
     */
    var $ModuleDBUpdateNecessary = false;
    
    /**
     *
     * @var boolean 
     */
    var $ModuleDBCreationNecessary = false;
    
    /**
     *
     * @var integer 
     */
    var $ModuleDBCurrentVersion = 0;
    
    /**
     * 
     * @var array 
     */
    var $ModuleDBInit = ["CREATE TABLE IF NOT EXISTS `##branch_export_presets` (
  `preset_id` int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `tree_id` int(11) NOT NULL,
  `pivot` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `cutoff` varchar(300) COLLATE utf8_unicode_ci NOT NULL,
  UNIQUE KEY `name` (`name`,`tree_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;"];
    
    /**
     *
     * @var array 
     */
    var $ModuleDBMigration = [
        
        //migration from version 0 to 1
        0 => [
            "ALTER TABLE `##branch_export_presets` CHANGE `l_file` `tree_id` INT(11) NOT NULL",
            "ALTER TABLE `##branch_export_presets` DROP PRIMARY KEY",
            "ALTER TABLE `##branch_export_presets` ADD `preset_id` INT NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (`preset_id`)",
            "ALTER TABLE `##branch_export_presets` ADD UNIQUE( `name`, `tree_id`)"
        ]
    ];
    
    
    
    
    /**
     * Sets the latest DB version as the current DB version.
     */
    protected function updateDBVersionSetting()
    {
        
        if($this->getCurrentDBVersion() == BRANCH_EXPORT_MODULE_DB_VERSION)
        {
            return;
        }
        
        $data = ["module_name"=>"branch_export","setting_name"=>"current_db_version","value"=>BRANCH_EXPORT_MODULE_DB_VERSION];
        $update_succeeded = Database::prepare("UPDATE ##module_setting  SET setting_value = :value WHERE module_name = :module_name AND setting_name =:setting_name")->execute($data)->rowCount() > 0;
        
        if(!$update_succeeded)
        {
            Database::prepare("INSERT INTO ##module_setting SET module_name = :module_name , setting_name =:setting_name , setting_value = :value")->execute($data);
        }
    }
    
    protected function getCurrentDBVersion()
    {
       
        $data = ["module_name"=>"branch_export","setting_name"=>"current_db_version"];
        $db_version_row = Database::prepare("SELECT * FROM ##module_setting WHERE module_name = :module_name AND setting_name = :setting_name")->execute($data)->fetchAll();
         
        if(!$db_version_row)
        {
            return 0;
        }

        return intval($db_version_row[0]->setting_value);
    }
    
    protected function execDBMigration($version)
    {
        if(isset($this->ModuleDBMigration[$version]))
            {
                foreach($this->ModuleDBMigration[$version] as $insctruction)
                {
                    Database::prepare($insctruction)->execute();
                }
            }
    }
    /**
     * 
     * Initializes the database table where presets are stored.
     */
    protected function createDB()
    {
        foreach($this->ModuleDBInit as $instruction){

            Database::prepare($instruction)->execute();
        }

        $this->updateDBVersionSetting();

    }
    /**
     * Updates the DB table structure to the latest version.
     */
    protected function updateDB()
    {
        $current_version = $this->getCurrentDBVersion();

        for($i = $current_version; $i < BRANCH_EXPORT_MODULE_DB_VERSION; $i++)
        {
            $this->execDBMigration($i);
        }


        $this->updateDBVersionSetting();
        
    }
    
    protected function uninstall()
    {
        $has_permission_to_uninstall = Filter::postBool("branch_export_uninstall_db") && Auth::isAdmin();
        
        if(!$has_permission_to_uninstall) {
            http_response_code(403);
            exit();   
        }
        
        if(!Filter::checkCsrf()){
            http_response_code(406);
            exit();
        }
        
        Database::prepare("DROP TABLE IF EXISTS ##branch_export_presets")->execute();
        Database::prepare("DELETE FROM ##module_setting WHERE module_name = 'branch_export'")->execute();
        Database::prepare("UPDATE ##module  SET status = 'disabled' WHERE module_name = 'branch_export'")->execute();
    }
    
    /**
     * Initializes the DB backend for the module. Performs necessary migration operations too.
     */
    protected function initDB()
    {
        $table_exists = Database::prepare("SHOW TABLES LIKE '##$this->TableNameWithoutPrefix'")->execute()->fetchAll();
        $asked_to_create = Filter::postBool("branch_export_create_db");
        $asked_to_update = Filter::postBool("branch_export_update_db");
        
        if($asked_to_create || $asked_to_update){
            if(!Filter::checkCsrf()){
                    http_response_code(406);
                    exit();
            }
        }
       
        if(!$table_exists)//create data table
        {

            $has_permission_to_create = $asked_to_create && Auth::isAdmin();
            
            if($has_permission_to_create){
                $this->createDB();
            }
            else{
                $this->ModuleDBCreationNecessary = true;
                return;
            }
        }
        else//update DB table, if necessary
        {
            $current_version = $this->getCurrentDBVersion();
            $has_permission_to_update = $asked_to_update && Auth::isAdmin();
            $needs_db_update = $current_version < BRANCH_EXPORT_MODULE_DB_VERSION;
            
            if(!$has_permission_to_update && $needs_db_update)//we do not have permission from the user to migrate the DB - we indicate the need and will display a warningfor the user 
            {
                $this->ModuleDBUpdateNecessary = true;
                return;
            }

            //otherwise we proceed with the update
            else if($needs_db_update){
                $this->updateDB();
            }
        }
    }
    
    /**
     * Used to sort indies and families for branch preview.
     * @param GedcomRecord[] $records Gedcom records to sort.
     */
    protected static function SortRecords(&$records)
    {
        uasort($records,'\Fisharebest\Webtrees\GedcomRecord::compare');
    }
    
    /**
     * Clears stored branch export options if needed.
     */
    protected function clearSelectionsIfNeeded()
    {
        if(Filter::getBool("clear_selections")){
        Session::put("branch_export_preset",null);
        Session::put("branch_export_pivot",null);
        Session::put("branch_export_cutoff",null);
        }
    }

    /**
     * Gets the presets  where the specified individual is the pivot point.
     * @param string $indi_xref
     * @return stdClass[]
     */
    protected function getPresetsFor($indi_xref)
    {
        $presets = [];
        
        foreach($this->Presets as $preset)
          {
                  if($preset->pivot !== $indi_xref ){continue;}

                  $presets[] = $preset;
          }
       
          return $presets;
    }
    /**
     * Gets the preset with the specified name.
     * @param integer $id Gets the preset with this id.
     * @return stdClass The preset, or null, if no preset found.
     */
    protected function getPreset($id)
    {
        if(!$this->Presets)
        {
            $this->loadPresets();
        }
        
        foreach($this->Presets as $preset)
        {
            if($preset->preset_id === $id)
            {
                return $preset;
            }
        }
        
        return null;
    }
    
    /**
     * Loads the currently seleted preset.
     */
    protected function loadSelectedPreset()
    {
        
        $this->SelectedPreset = Filter::getInteger("preset");
        
        if($this->SelectedPreset)
        {
            Session::put("branch_export_preset", $this->SelectedPreset);
        }
        else
        {
           $this->SelectedPreset = Session::get("branch_export_preset");
        }
        
        $this->SelectedPresetObj = $this->getPreset($this->SelectedPreset);
    }
    
    /**
     * Loads the selected pivot individual, if there is any.
     */
    protected function loadSelectedPivot()
    {
        global $WT_TREE;
        $this->PivotIndiXref = Filter::escapeHtml(Filter::get("pivot"));
        
        if($this->PivotIndiXref && BranchExportUtils::validatePivot($this->PivotIndiXref))
        {
            Session::put("branch_export_pivot", $this->PivotIndiXref);
        }
        else if($this->PivotIndiXref === NULL)
        {
           $this->PivotIndiXref = Session::get("branch_export_pivot");
        }
        else if($this->PivotIndiXref){
            $this->PivotIndiXref = NULL;
        }
        
        if ($this->PivotIndiXref) {
            $this->PivotIndi = Individual::getInstance($this->PivotIndiXref, $WT_TREE);
        }
        else{
            $this->PivotIndi = NULL;
        }
    }
    
    /**
     * Loads the currently selected cutoff points, if any.
     */
    protected function loadCutoffPoints()
    {
        $this->CutoffXrefs = Filter::get("cutoff");
        
        if(!$this->CutoffXrefs)
        {
           $this->CutoffXrefs = Session::get("branch_export_cutoff");
        }
        
        if(!is_array($this->CutoffXrefs) && $this->CutoffXrefs)
        {
            $this->CutoffXrefs = explode(",",$this->CutoffXrefs);
        }
        
        if (!$this->CutoffXrefs) {
            $this->CutoffXrefs = array();
        }
        
        if(BranchExportUtils::validateCutoffArray($this->CutoffXrefs)){
            Session::put("branch_export_cutoff", $this->CutoffXrefs);
        }
        else{
            $this->CutoffXrefs = array();
        }
    }
    /**
     * 
     * Initializes the module.
     */
    protected function init()
    {
        $this->initDB();
        
        if(!$this->ModuleDBUpdateNecessary && !$this->ModuleDBCreationNecessary){
        
        $this->cfgAction_DeletePresets();
        
        $this->cfgAction_CopyPresets();
        
        $this->clearSelectionsIfNeeded();
        
        $this->loadSelectedPreset();
       
        $this->loadSelectedPivot();
        
        $this->loadCutoffPoints();
        
        if($this->PivotIndi)
            $this->BranchGenerator = new BranchGenerator($this->PivotIndi, $this->CutoffXrefs);
        
        $this->loadPresets();
        }
    }
    
    /**
     * 
     * Loads the presets from the database.
     */
    protected function loadPresets()
    {
        global $WT_TREE;
        $tree_id = $WT_TREE->getTreeId();
        
        $this->Presets = Database::prepare("SELECT * FROM ##branch_export_presets WHERE tree_id = :tree_id ORDER BY name")->execute(["tree_id"=>$tree_id])->fetchAll();
        
    }
    
    /**
     * 
     * @global Tree $WT_TREE
     */
    protected function numRecordOfTypeInTree($type)
    {
        global $WT_TREE;
        
        $tree_id = $WT_TREE->getTreeId();
        
        $table_name = "";
        $id_field= "";
        $tree_id_field = "";
        $o_type = "";
        switch($type)
        {
            case Individual::RECORD_TYPE:
                $table_name="##individuals";
                $id_field="i_id";
                $tree_id_field="i_file";
                break;
            case Family::RECORD_TYPE:
                $table_name="##families";
                $id_field="f_id";
                $tree_id_field="f_file";
                break;
            case Media::RECORD_TYPE:
                $table_name="##media";
                $id_field="m_id";
                $tree_id_field="m_file";
                break;
            case Note::RECORD_TYPE:
                $table_name="##other";
                $id_field="o_id";
                $tree_id_field="o_file";
                $o_type = "NOTE";
                break;
            case Repository::RECORD_TYPE:
                
                if(WT_SCHEMA_VERSION > 37)//after migration 37, repos are moved to its own table
                {
                    $table_name="##repository";
                    $id_prefix="repository_id";
                    $tree_id_field="gedcom_id";
                }
                else//before that they were stored in the wt_other table
                    {
                    $table_name="##other";
                    $id_field="o_id";
                    $tree_id_field="o_file";
                    $o_type = "REPO";
                }

                break;
            case Source::RECORD_TYPE:
                $table_name="##sources";
                $id_field="s_id";
                $tree_id_field="s_file";
                break;
        }
        
        if(!$table_name)
        {
            return 0;
        }
        
        $query  = "SELECT COUNT(DISTINCT $id_field) FROM $table_name WHERE $tree_id_field = :tree_id";
        $query_params = ["tree_id"=>$tree_id];
        
        if($table_name === "##other")
        {
            $query_params["o_type"]=$o_type;
            $query.=" AND o_type = :o_type";
        }
        
        return intval(Database::prepare($query)->execute($query_params)->fetchOne());
    }
    

    /**
     * Gets the HTML printout of the module version (to be used in/near the page footer).
     * @return string
     */
    protected function getModuleVersionHTML()
    {   
        return "<p class='branch-export-version'>".I18N::translate("Branch export module")." - v".BRANCH_EXPORT_MODULE_VERSION."</p>";
    }
    
    /**
     * 
     * Performs the copy presets action, if needed.
     */
    protected function cfgAction_CopyPresets()
    {
        
      if(Filter::post('config_action') !== 'copy_presets' || Filter::get("mod_action") !== "branch_export_config")//no need to copy presets
      {
          return;
      }
      
      
      if(!Auth::isAdmin()) 
      { 
          http_response_code(403);
          exit();
      }
      
      if(!Filter::checkCsrf()){
          http_response_code(406);
          exit();
      }
      
      $copy_to_tree_id = intval(Filter::post('copy_to_tree_id'));
      $presets = Filter::post('presets');
      if(!$copy_to_tree_id || !$presets)
      {
          return ;
      }
      
      foreach($presets as $preset)
      {
          $preset_data = Database::prepare("SELECT * FROM ##branch_export_presets WHERE preset_id = :preset_id")->execute(["preset_id"=>intval($preset)])->fetchAll()[0];
          
          //now we need to assure that no preset for the target tree with the same name exists
          
          $conflicting_preset_found = Database::prepare("SELECT * FROM ##branch_export_presets WHERE tree_id = :tree_id AND name = :name")->execute(["tree_id"=>$copy_to_tree_id,"name"=>$preset_data->name])->fetchAll();
          
          //
          
          if($conflicting_preset_found){
              Session::put("preset_config_action_error", sprintf(I18N::translate("Preset with the same name (%s) already exists for the target tree."),$preset_data->name));
          }
          else{
          Database::prepare("INSERT INTO ##branch_export_presets SET name = :name, pivot = :pivot, cutoff = :cutoff, tree_id = :tree_id")->execute(["name"=>$preset_data->name, "pivot"=>$preset_data->pivot, "cutoff"=>$preset_data->cutoff,"tree_id"=>$copy_to_tree_id])->rowCount();
          }
      }
    }
    
    /**
     * Performs the delete presets action, if needed.
     */
    protected function cfgAction_DeletePresets()
    {
      if(Filter::post('config_action') !== 'delete_presets' || Filter::get("mod_action") !== "branch_export_config"){//no need to delete presets
          return;
      }
      
      if(!Auth::isAdmin()) 
      { 
          http_response_code(403);
          exit();
      }
      
      if(!Filter::checkCsrf()){
          http_response_code(406);
          exit();
      }
     
      $presets_to_delete = Filter::post('presets');
      
      if(!$presets_to_delete)
      {
          return;
      }
      
      foreach($presets_to_delete as $preset)
      {
          Database::prepare("DELETE FROM ##branch_export_presets WHERE preset_id = :preset")->execute(["preset"=>intval($preset)])->rowCount();
      }
    }
    
    
    protected function printDBCreateWarning()
    {
        ?>
        <div class="db-update-warning">
            <h1><?php echo I18N::translate("Branch export module - database initialization needed")?></h1>
            <p>
                <?php echo I18N::translate("It is required to initialize the data table where the branch export module stores the branch presets. This initialization is done automatically, but we ask for your permission so you have a chance to back up your data. The update should not harm your data in any way, but it is always a good idea to create a backup first.")?>
            </p>
            
            <?php if(Auth::isAdmin()):?>
            <p>
                <?php echo I18N::translate("As soon as you are ready to perform the initialization, click the link below.")?>
            </p>
            <form action="<?php echo WT_BASE_URL?>module.php?mod=branch_export" method="post">
                <input type="hidden" name="branch_export_create_db" value="1">
                <?php echo Filter::getCsrf()?>
                <button type="submit"><?php echo I18N::translate("Perform Initialization")?></button>
            </form>
            <?php else: ?>
            <p>
                <strong><?php echo I18N::translate("Only administrators can initialize the data table.");?></strong>
            </p>
            <?php endif;?>
        </div>
        <?php
    }
    
    /**
     * Prints a warning that the module DB table must be updated. Also provides the link to perform the update. 
     */
    protected function printDBUpdateWarning()
    {
        ?>
        <div class="db-update-warning">
            <h1><?php echo I18N::translate("Branch export module - database update needed")?></h1>
            <p>
                <?php echo I18N::translate("It is required to update the data table where the branch export module stores the branch presets. This update is done automatically, but we ask for your permission so you have a chance to back up your data. The update should not harm your data in any way, but it always a good idea to create a backup first.")?>
            </p>
            
            <?php if(Auth::isAdmin()):?>
            <p>
                <?php echo I18N::translate("As soon as you are ready to perform the update, click the link below.")?>
            </p>
            <form action="<?php echo WT_BASE_URL?>module.php?mod=branch_export" method="post">
                <input type="hidden" name="branch_export_update_db" value="1">
                <?php echo Filter::getCsrf()?>
                <button type="submit"><?php echo I18N::translate("Perform Update")?></button>
            </form>
            <?php else: ?>
            <p>
                <strong><?php echo I18N::translate("Only administrators can update the database.");?></strong>
            </p>
            <?php endif;?>
        </div>
        <?php
    }
    /**
     * Prints the branch export module config page.
     */
    protected function printConfigPage()
    {
        $tree_list = Database::prepare("SELECT * FROM ##gedcom ORDER BY gedcom_name")->execute()->fetchAll();
        $preset_list = Database::prepare("SELECT * FROM ##branch_export_presets ORDER BY name")->execute()->fetchAll();
        
        for($i = 0; $i < count($preset_list); $i++)
        {
            $preset_list[$i]->orphaned = Database::prepare("SELECT COUNT(*) FROM ##gedcom WHERE gedcom_id = :tree_id")->execute(["tree_id"=>$preset_list[$i]->tree_id])->fetchOne() < 1;
            
        }
        
        ?>
            <h1 class="branch-export-heading"><?php echo I18N::translate("Branch export config")?></h1>
            <?php if(!Auth::isAdmin()): ?>
            <p class="admin-only-warning"><?php echo I18N::translate("Settings are only available for admins.")?></p>
            <?php else:?>
            
            <form id="branchcfg" method="post" name="branchcfg" action="module.php?mod=branch_export&amp;mod_action=branch_export_config">
                <input type="hidden" name="config_action" value="">
                <?php echo Filter::getCsrf()?>
                <table>
                    <thead>
                        <tr>
                            <td class="topbottombar" colspan="3"><h4><?php echo I18N::translate("Manage presets")?></h4></td>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="optionbox">
                                <h5><?php echo I18N::translate("Tree:")?></h5>
                                <p>
                                <select id="tree_id" name="tree_id">
                                    <option value="NULL"><?php echo I18N::translate("All trees")?></option>
                                    <optgroup label="<?php echo I18N::translate("Select specific tree")?>">
                                    <?php foreach($tree_list as $tree):?>
                                    <option value="<?php echo $tree->gedcom_id?>"><?php echo $tree->gedcom_name?></option>
                                    <?php endforeach;?>
                                    </optgroup>
                                </select>
                                </p>
                            </td>
                            <td class="optionbox">
                                <h5><?php echo I18N::translate("Select preset(s):")?></h5>
                                <p>
                                <select id="presets" name="presets[]" multiple>
                                    <?php foreach($preset_list as $preset):?>
                                    <option value="<?php echo $preset->preset_id?>" <?php echo ($preset->orphaned)?"class=\"preset-orphaned\"":""?>
                                            data-tree="<?php echo $preset->tree_id?>"
                                            data-pivot="<?php echo $preset->pivot?>"
                                            data-cutoff="<?php echo htmlspecialchars($preset->cutoff)?>"><?php echo $preset->name?></option>
                                    <?php endforeach;?>
                                </select>
                                </p>
                                <p>
                                    <input type="button" id="select_orphaned" value="<?php echo I18N::translate("Select orphaned")?>"> 
                                </p>
                            </td>
                            <td class="optionbox selected-preset-details">
                                <table>
                                    <caption><h5><?php echo I18N::translate("Preset details")?></h5></caption>
                                    <tbody>
                                        <tr>
                                            <td><?php echo I18N::translate("Tree:")?></td>
                                            <td id="selected_preset_tree"></td>
                                        </tr>
                                        <tr>
                                            <td><?php echo I18N::translate("Name:")?></td><td id="selected_preset_name"></td>
                                        </tr>
                                        <tr>
                                            <td><?php echo I18N::translate("Pivot:")?></td><td id="selected_preset_pivot"></td>
                                        </tr>
                                        <tr>
                                            <td><?php echo I18N::translate("Cutoff:")?></td><td id="selected_preset_cutoff"></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <td class="optionbox"></td>
                            <td class="optionbox" colspan="2">
                                <p>
                                <input type="submit" id="delete_presets" value="<?php echo I18N::translate("Delete selected presets")?>">
                                </p>
                                <hr>
                                <input type="submit" id="copy_presets" value="<?php echo I18N::translate("Copy selected presets to:")?>">
                                <select id="copy_to_tree_id" name="copy_to_tree_id">
                                    <?php foreach($tree_list as $tree):?>
                                    <option value="<?php echo $tree->gedcom_id?>"><?php echo $tree->gedcom_name?></option>
                                    <?php endforeach;?>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </form>
            <form id="uninstall_branch_export_module" method="post" name="branchuninst" action="module.php?mod=branch_export&mod_action=uninstall">
                <input type="hidden" name="branch_export_uninstall_db" value="1">
                <?php echo Filter::getCsrf()?>
                <input type="submit" id="uninstall" value="<?php echo I18N::translate("uninstall")?>">
            </form>
            <?php endif;
            
            $config_error = Session::get("preset_config_action_error");
            if($config_error)
            {
                ?>
                <h3 class="preset-config-error"><?php echo $config_error;?></h3>
                <?php
                Session::put("preset_config_action_error",null);
            }
            
            
    }
    
    /**
     * Loads a branch based on a preset.
     */
    protected function loadPresetBranch()
    {
        
        global $WT_TREE;
        
        $preset = $this->getPreset($this->SelectedPreset);
        
        if(!$preset)
        {
            return;
        }
        
        $this->PivotIndi = Individual::getInstance($preset->pivot, $WT_TREE);
        $this->CutoffXrefs = explode(",",$preset->cutoff);
        $this->BranchGenerator = new BranchGenerator($this->PivotIndi, $this->CutoffXrefs);
    }
        
    /**
     * Prints the cutoff point input elements. There can be as many cutoff point as the user wants.
     * 
     * @param string $value The XREF of the individual to be used as cutoff point.
     * @param integer $idx The index of the current cutoff point.
     */
    protected function printCutoffpointInput($value,$idx)
    {
        
        echo "<tr class=\"branch-cutoff-row\">
                                            <td class=\"optionbox\">";
                ?>


                                                    <label <?php echo "for=\"branch_cutoff_$idx\"";?> class="branch-cutoff-label"><?php echo I18N::translate("Cutoff point %d:",$idx)?></label>
                                                    <input type="text" data-autocomplete-type="INDI" name="cutoff[]" <?php echo "id=\"branch_cutoff_$idx\"";?> size="8" value="<?php echo $value;?>">
                                            <?php echo "</td>
                                            <td class=\"optionbox\">";
                                                ?>
                                                    <?php echo FunctionsPrint::printFindIndividualLink("branch_cutoff_$idx"); ?>
                                                    <?php echo FunctionsPrint::printFindFamilyLink("branch_cutoff_$idx"); ?>
                                                <a class="icon-remove" title="<?php echo I18N::translate("Remove cutoff point")?>" href="#" onclick="branchExport_RemoveCutoffPoint(event)"></a>
                                            
<?php
    echo "</td>
        </tr>";
    }
    
    /**
     * Prints branch export help content.
     */
    protected function printHelp(){
        
        $help_data = [
            
            I18N::translate('How branch export works') => 
                I18N::translate("Branch export module helps you export a portion of a tree in a way that is not possible using the built-in export features.")."<br><br>".
                I18N::translate("Branch export traverses the entire tree, starting from a specific individual (called pivot point). First it will select the immediate relatives of the pivot individual (e. g. parents, children, spouses, siblings). Then continues the traversal recursively with their relatives, processing them like the pivot point - unless they are one of the predefined blocking individuals (called cutoff points). The traversal will stop when all non-blocked individuals are processed.").
            "<br><br>".I18N::translate("You can use an unlimited number of cutoff points."),
            
            I18N::translate('What records are included in the branch?') => I18N::translate("The pivot point is always included. Cutoff points are also included if they can be reached during the traversal, but the traversal algorithm will stop traversing the tree when it hits a cutoff point, and thus their relatives - that are not reachable using a different path - will not be included. If an individual is included in the branch, all linked records (families, media objects, sources, notes, repositories) are also added.")."<br><br><strong>".
            I18N::translate("Note: Exporting the content of the branch requires that the Clippings cart module is installed and activated.")."</strong>",
            
            I18N::translate('What records can be used as pivot point?') => I18N::translate("Only individuals can be used as pivot point. Any individual in the tree can be used."),
            
            I18N::translate('What records can be used as cutoff points?') => I18N::translate("Only individuals and families can be used as cutoff points. Any individual or family in the tree can be used. Using a family as cutoff point is a shortcut for adding all individuals in that family as cutoff points.")
                
        ];
        
        $release_log = [
            "1.1.0" => [
                I18N::translate("Added Hungarian and German localization"),
                I18N::translate("Fixed: 'Unable to delete preset (None)' error message when pressing Delete without selecting a preset."),
                I18N::translate("Fixed: when clicking Delete, the confirmation dialog used the value of the 'Name' input field instead the name of the selected Preset."),
                I18N::translate("Fixed: when renaming a preset with the new name being identical to the current name, 'duplicate key' error message was displayed."),
                I18N::translate("Fixed: after saving/deleting/renaming a preset the preset list was refreshed incorrectly, preventing further rename/delete operations (until page refresh)."),
                I18N::translate("Several minor UI fixes and improvements for Branch export main page and config page"),
                I18N::translate("User authorization is now properly checked. Only users that are members of the tree can work with the branch export module and the presets belonging to that tree.")
            ],     
            "1.0.0" =>[
                I18N::translate("First public release"),
                I18N::translate("Improved help section, as well as other changes/fixes to certain text elements"),
                I18N::translate("Warning message is now displayed if Clippings cart module is disabled")
            ],
            "0.9.3" => [
                I18N::translate("Added install/uninstall features"),
                I18N::translate("Added release log"),
                I18N::translate("Added Save &amp; Rename command"),
                I18N::translate("Clicking 'Delete' will now ask for confirmation before deleting the preset"),
                I18N::translate("Fixed: 'Name' field contained the id of the preset (instead of the name) after clicking 'Preview'; 'Load preset' also lost its stored value, and was reset to '(None)'.")
            ]   
       ];
        
        ?>
        <h1 class="branch-export-heading"><?php echo I18N::translate('Branch export help')?></h1>
        <div id="branchexport_help">
            <?php foreach($help_data as $title=>$content):?>
            <h3><?php echo $title?></h3>
            <div><p><?php echo $content?></p></div>
            <?php endforeach;?>
            <h3><strong><?php echo I18N::translate("Release log")?></strong></h3>
            <div>
                <ul>
                    <?php foreach($release_log as $version=>$log_items):?>
                    <li>
                        <strong><?php echo $version?></strong>:
                        <ul>
                            <?php foreach($log_items as $log_item):?>
                            <li><?php echo $log_item?></li>
                            <?php endforeach;?>
                        </ul>
                    </li>
                    <?php endforeach;?>
                </ul>
            </div>
        </div>
        <?php 
    }
    
    /**
     * Prints the main branch export UI.
     */
    protected function printMainBranchInput()
    {
  
    ?>
    
    <h1 class="branch-export-heading"><?php echo $this->getTitle();?></h1>
    <div>
        
        <form method="post" name="branchexp" action="<?php echo $this->directory?>/exportbranch.php" id="branchexp">
                <?php echo Filter::getCsrf();?>
                <table>
                            <thead>
                                    <tr>
                                            <td colspan="2" class="topbottombar" style="text-align:center; ">
                                                    <?php echo I18N::translate('Branch export'); ?>
                                            </td>
                                    </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="2" class="optionbox" style="text-align: center">
                                        <?php echo I18N::translate('Branch settings')?>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" class="optionbox">
                                        <?php echo I18N::translate('Load preset:')?>
                                        <select name="preset" id="saved_branch_presets" onchange="branchExport_OnPresetSelected(event)">
                                            <option value="NULL"><?php echo I18N::translate('(None)')?></option>
                                            <?php foreach($this->Presets as $export_settings):?>
                                            <option value="<?php echo $export_settings->preset_id?>" data-pivot="<?php echo $export_settings->pivot?>" data-cutoff="<?php echo $export_settings->cutoff?>" <?php if($this->SelectedPreset == $export_settings->preset_id) echo "selected";?>><?php echo $export_settings->name?></option>
                                            <?php endforeach;?>
                                        </select>
                                        
                                        
                                    </td>
                                </tr>
                                    <tr>
                                            <td class="optionbox">


                                                    <label for="branch_pivot" style="display: inline-block; min-width: 80px;" ><?php echo I18N::translate('Pivot point:')?></label>
                                                    <input type="text" data-autocomplete-type="INDI" name="pivot" id="branch_pivot" size="8" required value="<?php echo $this->PivotIndi !== null ? $this->PivotIndi->getXref(): $this->PivotIndiXref?>">
                                            </td>
                                            <td class="optionbox">
                                                    <?php echo FunctionsPrint::printFindIndividualLink('branch_pivot'); ?>

                                            </td>
                                    </tr>
                                    <?php 


                                        if(!$this->CutoffXrefs)
                                        {
                                            $this->printCutoffpointInput("", 1);
                                        }
                                        for($idx = 1; $idx<=count($this->CutoffXrefs);$idx++)
                                        {
                                            $this->printCutoffpointInput($this->CutoffXrefs[$idx-1], $idx);
                                        }
                                        
                                    ?>
                                    <tr>
                                        <td class="optionbox" colspan="2" style="text-align: center">
                                            <input type="button" id="add_cutoff_point" onclick="branchExport_OnAddCutoffPointClick()" value="<?php echo I18N::translate("Add cutoff point")?>">
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="optionbox" colspan="2" style="text-align: center">
                                            <input type="button" value="<?php echo I18N::translate('Preview branch')?>" data-action="branch-preview" onclick="branchExport_OnShowPreview('<?php echo $this->directory?>')">
                                            <input type="submit" value="<?php echo I18N::translate('Export branch')?>">
                                           
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="optionbox" colspan="2" style="text-align: center">
                                            <?php echo I18N::translate("Save branch export preset");?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="optionbox" colspan="2">
                                            <label for="branch_preset_name"><?php echo I18N::translate("Name:")?></label>
                                            <input name="branch_preset_name" id="branch_preset_name" value="<?php echo $this->SelectedPresetObj? $this->SelectedPresetObj->name : ""?>">
                                            <input type="button" id="save_branch_preset" value="<?php echo I18N::translate("Save")?>" onclick="branchExport_OnSavePreset('<?php echo $this->directory?>')">
                                            <input type="button" id="rename_branch_preset" value="<?php echo I18N::translate("Rename &amp; Save")?>" onclick="branchExport_OnSavePreset('<?php echo $this->directory?>',1)">
                                            <input type="button" id="delete_branch_preset" value="<?php echo I18N::translate("Delete")?>" onclick="branchExport_OnDeletePreset('<?php echo $this->directory?>')"> 
                                        </td>
                                    </tr>
                            </tbody>
                            <tfoot>
                                    <tr>
                                            <th colspan="2">

                                                    <em><?php echo I18N::translate("Note: saved presets are shared with all members of the tree.")?></em>
                                            </th>
                                    </tr>
                            </tfoot>
                    </table>
            </form>
        </div>
        <div id="branch-preview-content">
            
        </div>
        <?php 
        if(!$this->isClippingsCartEnabled()):
            ?>
    <h3 class="warning-lippings-cart-disabled"><?php echo I18N::translate("Clippings cart module is disabled. 'Export branch' will not work!")?></h3>
    <?php
        endif;
         }
    protected function isClippingsCartEnabled()
    {
        return Database::prepare("SELECT IF(status = 'enabled', true, false) FROM ##module WHERE module_name = 'clippings'")->execute()->fetchOne();
    }
    
    protected function printAuthWarning()
    {
        ?>
    <h1 class="branch-export-heading"><?php echo $this->getTitle();?></h1>
    <p>
        <?php echo I18N::translate("You are not authorized to access this module. You are not logged in as a member of the current tree.");?>
    </p>
    <?php
    }

    /** @var string location of the branch export module files */
    var $directory;

    public function __construct()
    {
        
        parent::__construct('branch_export');
        $this->directory = WT_MODULES_DIR . $this->getName();
        $this->action = Filter::get('mod_action');

        // register the namespaces
        $loader = new ClassLoader();
        $loader->addPsr4('BlasiusSecundus\\WebtreesModules\\BranchExport\\', $this->directory);
        $loader->register();
  
    }

    /* ****************************
     * Module configuration
     * ****************************/

    /** {@inheritdoc} */
    public function getName()
    {
        // warning: Must match (case-sensitive!) the directory name!
        return "branch_export";
    }

    /** {@inheritdoc} */
    public function getTitle()
    {
        return I18N::translate("Branch export");
    }

    /** {@inheritdoc} */
    public function getDescription()
    {
        return I18N::translate("Allows exporting a branch of a tree. Requires Clippings cart module!");
    }
    
    public function modAction($mod_action) {
        
        $this->init();
        
        if($mod_action === "uninstall")
        {
            $this->uninstall();
            header("Location: ".WT_BASE_URL);
        }
        else if($mod_action === "translate")
        {
            $src_strings = Filter::post("strings");
            
            if(is_array($src_strings)){
                foreach($src_strings as $src)
                {
                    $translations[$src] = I18N::translate($src);
                }
            }


            echo json_encode( $translations );
            exit();
        }
       
        
        global $controller, $WT_TREE;
        $controller = new PageController;
        $controller
                ->setPageTitle($this->getTitle())
                ->addExternalJavascript($this->directory."/assets/sprintf.min.js")
                ->addExternalJavascript($this->directory."/assets/translate.js")
                ->addExternalJavascript($this->directory."/assets/branch_export.js")
                ->addExternalJavascript(WT_AUTOCOMPLETE_JS_URL)
                ->addInlineJavascript('autocomplete();')
                ->pageHeader();
        if(Filter::get("mod_action") == "branch_export_config")
         {
             $controller
                ->addExternalJavascript($this->directory."/assets/branch_config.js");
         }
        
        if(!Auth::isMember($WT_TREE))
        {
            $this->printAuthWarning();
            return;
        }
        
        if($this->ModuleDBUpdateNecessary)
        {
            $this->printDBUpdateWarning();
        }
        else if($this->ModuleDBCreationNecessary)
        {
            $this->printDBCreateWarning();
        }
        
        else if($mod_action == "branch_export_config")
        {
            $this->printConfigPage();
        }
        
        else if($mod_action === "help")
        {
            $this->printHelp();
        }
        
        else {
            $this->printMainBranchInput();    
        }
        
        
        
        echo $this->getModuleVersionHTML("contact-links");

    }

    /** {@inheritdoc} */
    public function defaultAccessLevel()
    {
        # Auth::PRIV_PRIVATE actually means public.
        # Auth::PRIV_NONE - no acces to anybody.
        return Auth::PRIV_USER;
    }
    
    /* ****************************
     * ModuleMenuInterface
     * ****************************/
    
    /** {@inheritdoc} */
    public  function getMenu() {
                   
        global $controller;
         $controller
                ->addExternalJavascript($this->directory."/assets/branchexport_add_menu_css.js");           

        if(Auth::isAdmin())
        {
            $submenu[] = new Menu(I18N::translate('Config'), $this->getConfigLink() , 'menu-admin', ['rel' => 'nofollow']);
        }
        $submenu[]  =  new Menu(I18N::translate('Help'), 'module.php?mod=branch_export&amp;mod_action=help', 'menu-help-faq', ['rel' => 'nofollow']);
        
        
        $menu = new Menu($this->getTitle(), 'module.php?mod=branch_export', 'menu-branch-export', ['rel' => 'nofollow'],$submenu);
        
        return $menu;
    }
    /** {@inheritdoc} */
    public function defaultMenuOrder() {
        return 1;
    }
    
     /* ****************************
     * ModuleTabInterface
     * ****************************/
    
    /** {@inheritdoc} */
    public function canLoadAjax() {
        return false;
    }
    
    /** {@inheritdoc} */
    public function getPreLoadContent() {
        return "";
    }
    
    /** {@inheritdoc} */
    public function getTabContent() {
        
       $this->init(); 
       
       if($this->ModuleDBUpdateNecessary)
       {
           return I18N::translate("Branch export module needs a database update. Please visit the module's main page to perform this update!");
       }
       
       global $controller;
       $indi = $controller->getSignificantIndividual();
       
       $presets = $this->getPresetsFor($indi->getXref());
       
       $new_branch_link = WT_BASE_URL . "module.php?mod=branch_export&amp;clear_selections=1&amp;pivot={$indi->getXref()}";
       
       $retval = 
               "<p>".I18N::translate("Export new branch from:")." <a href='$new_branch_link' target='_blank'>{$indi->getFullName()}</a></p>".
               "<p>".I18N::translate("Export preset branch:")."<form action='".WT_BASE_URL."modules_v3/branch_export/exportbranch.php' method='post'>"
                       . "<input type='hidden' name='use_preset' value='1'>".
                       Filter::getCsrf();
       
        if($presets){      
              $retval.="<select name='preset'>";
       
                  foreach($presets as $preset)
                  {
                          $retval.="<option value='{$preset->preset_id}'>{$preset->name}</option>";
                  }

                   $retval.="</select>";
                   $retval.="<input type='submit' value='".I18N::translate("Export this branch")."'>";
        }
        else {
            $retval.=I18N::translate("No branch export presets defined for this individual.");
        }
       $retval.="</form></p>";
       $retval.=$this->getModuleVersionHTML();
       
       
        return $retval;
    }
    
    /** {@inheritdoc} */
    public function defaultTabOrder() {
        return 90;
    }
    
    /** {@inheritdoc} */
    public function isGrayedOut() {
        return false;
    }
    
    /** {@inheritdoc} */
    public function hasTabContent() {
        return true;
    }
    
    /* ****************************
     * ModuleConfigInterface
     * ****************************/
    
    public function getConfigLink()
    {
        return 'module.php?mod=' . $this->getName() . '&amp;mod_action=branch_export_config';
    }
}

return new BranchExportModule();