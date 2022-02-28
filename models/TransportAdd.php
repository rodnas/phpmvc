<?php

namespace PHPMaker2022\phpmvc;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Page class
 */
class TransportAdd extends Transport
{
    use MessagesTrait;

    // Page ID
    public $PageID = "add";

    // Project ID
    public $ProjectID = PROJECT_ID;

    // Table name
    public $TableName = 'transport';

    // Page object name
    public $PageObjName = "TransportAdd";

    // View file path
    public $View = null;

    // Title
    public $Title = null; // Title for <title> tag

    // Rendering View
    public $RenderingView = false;

    // Page headings
    public $Heading = "";
    public $Subheading = "";
    public $PageHeader;
    public $PageFooter;

    // Page layout
    public $UseLayout = true;

    // Page terminated
    private $terminated = false;

    // Page heading
    public function pageHeading()
    {
        global $Language;
        if ($this->Heading != "") {
            return $this->Heading;
        }
        if (method_exists($this, "tableCaption")) {
            return $this->tableCaption();
        }
        return "";
    }

    // Page subheading
    public function pageSubheading()
    {
        global $Language;
        if ($this->Subheading != "") {
            return $this->Subheading;
        }
        if ($this->TableName) {
            return $Language->phrase($this->PageID);
        }
        return "";
    }

    // Page name
    public function pageName()
    {
        return CurrentPageName();
    }

    // Page URL
    public function pageUrl($withArgs = true)
    {
        $route = GetRoute();
        $args = $route->getArguments();
        if (!$withArgs) {
            foreach ($args as $key => &$val) {
                $val = "";
            }
            unset($val);
        }
        $url = rtrim(UrlFor($route->getName(), $args), "/") . "?";
        if ($this->UseTokenInUrl) {
            $url .= "t=" . $this->TableVar . "&"; // Add page token
        }
        return $url;
    }

    // Show Page Header
    public function showPageHeader()
    {
        $header = $this->PageHeader;
        $this->pageDataRendering($header);
        if ($header != "") { // Header exists, display
            echo '<p id="ew-page-header">' . $header . '</p>';
        }
    }

    // Show Page Footer
    public function showPageFooter()
    {
        $footer = $this->PageFooter;
        $this->pageDataRendered($footer);
        if ($footer != "") { // Footer exists, display
            echo '<p id="ew-page-footer">' . $footer . '</p>';
        }
    }

    // Validate page request
    protected function isPageRequest()
    {
        global $CurrentForm;
        if ($this->UseTokenInUrl) {
            if ($CurrentForm) {
                return $this->TableVar == $CurrentForm->getValue("t");
            }
            if (Get("t") !== null) {
                return $this->TableVar == Get("t");
            }
        }
        return true;
    }

    // Constructor
    public function __construct()
    {
        global $Language, $DashboardReport, $DebugTimer;
        global $UserTable;

        // Initialize
        $GLOBALS["Page"] = &$this;

        // Language object
        $Language = Container("language");

        // Parent constuctor
        parent::__construct();

        // Table object (transport)
        if (!isset($GLOBALS["transport"]) || get_class($GLOBALS["transport"]) == PROJECT_NAMESPACE . "transport") {
            $GLOBALS["transport"] = &$this;
        }

        // Table name (for backward compatibility only)
        if (!defined(PROJECT_NAMESPACE . "TABLE_NAME")) {
            define(PROJECT_NAMESPACE . "TABLE_NAME", 'transport');
        }

        // Start timer
        $DebugTimer = Container("timer");

        // Debug message
        LoadDebugMessage();

        // Open connection
        $GLOBALS["Conn"] = $GLOBALS["Conn"] ?? $this->getConnection();

        // User table object
        $UserTable = Container("usertable");
    }

    // Get content from stream
    public function getContents($stream = null): string
    {
        global $Response;
        return is_object($Response) ? $Response->getBody() : ob_get_clean();
    }

    // Is lookup
    public function isLookup()
    {
        return SameText(Route(0), Config("API_LOOKUP_ACTION"));
    }

    // Is AutoFill
    public function isAutoFill()
    {
        return $this->isLookup() && SameText(Post("ajax"), "autofill");
    }

    // Is AutoSuggest
    public function isAutoSuggest()
    {
        return $this->isLookup() && SameText(Post("ajax"), "autosuggest");
    }

    // Is modal lookup
    public function isModalLookup()
    {
        return $this->isLookup() && SameText(Post("ajax"), "modal");
    }

    // Is terminated
    public function isTerminated()
    {
        return $this->terminated;
    }

    /**
     * Terminate page
     *
     * @param string $url URL for direction
     * @return void
     */
    public function terminate($url = "")
    {
        if ($this->terminated) {
            return;
        }
        global $ExportFileName, $TempImages, $DashboardReport, $Response;

        // Page is terminated
        $this->terminated = true;

         // Page Unload event
        if (method_exists($this, "pageUnload")) {
            $this->pageUnload();
        }

        // Global Page Unloaded event (in userfn*.php)
        Page_Unloaded();

        // Export
        if ($this->CustomExport && $this->CustomExport == $this->Export && array_key_exists($this->CustomExport, Config("EXPORT_CLASSES"))) {
            $content = $this->getContents();
            if ($ExportFileName == "") {
                $ExportFileName = $this->TableVar;
            }
            $class = PROJECT_NAMESPACE . Config("EXPORT_CLASSES." . $this->CustomExport);
            if (class_exists($class)) {
                $tbl = Container("transport");
                $doc = new $class($tbl);
                $doc->Text = @$content;
                if ($this->isExport("email")) {
                    echo $this->exportEmail($doc->Text);
                } else {
                    $doc->export();
                }
                DeleteTempImages(); // Delete temp images
                return;
            }
        }
        if (!IsApi() && method_exists($this, "pageRedirecting")) {
            $this->pageRedirecting($url);
        }

        // Close connection
        CloseConnections();

        // Return for API
        if (IsApi()) {
            $res = $url === true;
            if (!$res) { // Show error
                WriteJson(array_merge(["success" => false], $this->getMessages()));
            }
            return;
        } else { // Check if response is JSON
            if (StartsString("application/json", $Response->getHeaderLine("Content-type")) && $Response->getBody()->getSize()) { // With JSON response
                $this->clearMessages();
                return;
            }
        }

        // Go to URL if specified
        if ($url != "") {
            if (!Config("DEBUG") && ob_get_length()) {
                ob_end_clean();
            }

            // Handle modal response
            if ($this->IsModal) { // Show as modal
                $row = ["url" => GetUrl($url), "modal" => "1"];
                $pageName = GetPageName($url);
                if ($pageName != $this->getListUrl()) { // Not List page
                    $row["caption"] = $this->getModalCaption($pageName);
                    if ($pageName == "TransportView") {
                        $row["view"] = "1";
                    }
                } else { // List page should not be shown as modal => error
                    $row["error"] = $this->getFailureMessage();
                    $this->clearFailureMessage();
                }
                WriteJson($row);
            } else {
                SaveDebugMessage();
                Redirect(GetUrl($url));
            }
        }
        return; // Return to controller
    }

    // Get records from recordset
    protected function getRecordsFromRecordset($rs, $current = false)
    {
        $rows = [];
        if (is_object($rs)) { // Recordset
            while ($rs && !$rs->EOF) {
                $this->loadRowValues($rs); // Set up DbValue/CurrentValue
                $row = $this->getRecordFromArray($rs->fields);
                if ($current) {
                    return $row;
                } else {
                    $rows[] = $row;
                }
                $rs->moveNext();
            }
        } elseif (is_array($rs)) {
            foreach ($rs as $ar) {
                $row = $this->getRecordFromArray($ar);
                if ($current) {
                    return $row;
                } else {
                    $rows[] = $row;
                }
            }
        }
        return $rows;
    }

    // Get record from array
    protected function getRecordFromArray($ar)
    {
        $row = [];
        if (is_array($ar)) {
            foreach ($ar as $fldname => $val) {
                if (array_key_exists($fldname, $this->Fields) && ($this->Fields[$fldname]->Visible || $this->Fields[$fldname]->IsPrimaryKey)) { // Primary key or Visible
                    $fld = &$this->Fields[$fldname];
                    if ($fld->HtmlTag == "FILE") { // Upload field
                        if (EmptyValue($val)) {
                            $row[$fldname] = null;
                        } else {
                            if ($fld->DataType == DATATYPE_BLOB) {
                                $url = FullUrl(GetApiUrl(Config("API_FILE_ACTION") .
                                    "/" . $fld->TableVar . "/" . $fld->Param . "/" . rawurlencode($this->getRecordKeyValue($ar))));
                                $row[$fldname] = ["type" => ContentType($val), "url" => $url, "name" => $fld->Param . ContentExtension($val)];
                            } elseif (!$fld->UploadMultiple || !ContainsString($val, Config("MULTIPLE_UPLOAD_SEPARATOR"))) { // Single file
                                $url = FullUrl(GetApiUrl(Config("API_FILE_ACTION") .
                                    "/" . $fld->TableVar . "/" . Encrypt($fld->physicalUploadPath() . $val)));
                                $row[$fldname] = ["type" => MimeContentType($val), "url" => $url, "name" => $val];
                            } else { // Multiple files
                                $files = explode(Config("MULTIPLE_UPLOAD_SEPARATOR"), $val);
                                $ar = [];
                                foreach ($files as $file) {
                                    $url = FullUrl(GetApiUrl(Config("API_FILE_ACTION") .
                                        "/" . $fld->TableVar . "/" . Encrypt($fld->physicalUploadPath() . $file)));
                                    if (!EmptyValue($file)) {
                                        $ar[] = ["type" => MimeContentType($file), "url" => $url, "name" => $file];
                                    }
                                }
                                $row[$fldname] = $ar;
                            }
                        }
                    } else {
                        $row[$fldname] = $val;
                    }
                }
            }
        }
        return $row;
    }

    // Get record key value from array
    protected function getRecordKeyValue($ar)
    {
        $key = "";
        if (is_array($ar)) {
            $key .= @$ar['id'];
        }
        return $key;
    }

    /**
     * Hide fields for add/edit
     *
     * @return void
     */
    protected function hideFieldsForAddEdit()
    {
        if ($this->isAdd() || $this->isCopy() || $this->isGridAdd()) {
            $this->id->Visible = false;
        }
    }

    // Lookup data
    public function lookup($ar = null)
    {
        global $Language, $Security;

        // Get lookup object
        $fieldName = $ar["field"] ?? Post("field");
        $lookup = $this->Fields[$fieldName]->Lookup;

        // Get lookup parameters
        $lookupType = $ar["ajax"] ?? Post("ajax", "unknown");
        $pageSize = -1;
        $offset = -1;
        $searchValue = "";
        if (SameText($lookupType, "modal") || SameText($lookupType, "filter")) {
            $searchValue = $ar["q"] ?? Param("q") ?? $ar["sv"] ?? Post("sv", "");
            $pageSize = $ar["n"] ?? Param("n") ?? $ar["recperpage"] ?? Post("recperpage", 10);
        } elseif (SameText($lookupType, "autosuggest")) {
            $searchValue = $ar["q"] ?? Param("q", "");
            $pageSize = $ar["n"] ?? Param("n", -1);
            $pageSize = is_numeric($pageSize) ? (int)$pageSize : -1;
            if ($pageSize <= 0) {
                $pageSize = Config("AUTO_SUGGEST_MAX_ENTRIES");
            }
        }
        $start = $ar["start"] ?? Param("start", -1);
        $start = is_numeric($start) ? (int)$start : -1;
        $page = $ar["page"] ?? Param("page", -1);
        $page = is_numeric($page) ? (int)$page : -1;
        $offset = $start >= 0 ? $start : ($page > 0 && $pageSize > 0 ? ($page - 1) * $pageSize : 0);
        $userSelect = Decrypt($ar["s"] ?? Post("s", ""));
        $userFilter = Decrypt($ar["f"] ?? Post("f", ""));
        $userOrderBy = Decrypt($ar["o"] ?? Post("o", ""));
        $keys = $ar["keys"] ?? Post("keys");
        $lookup->LookupType = $lookupType; // Lookup type
        $lookup->FilterValues = []; // Clear filter values first
        if ($keys !== null) { // Selected records from modal
            if (is_array($keys)) {
                $keys = implode(Config("MULTIPLE_OPTION_SEPARATOR"), $keys);
            }
            $lookup->FilterFields = []; // Skip parent fields if any
            $lookup->FilterValues[] = $keys; // Lookup values
            $pageSize = -1; // Show all records
        } else { // Lookup values
            $lookup->FilterValues[] = $ar["v0"] ?? $ar["lookupValue"] ?? Post("v0", Post("lookupValue", ""));
        }
        $cnt = is_array($lookup->FilterFields) ? count($lookup->FilterFields) : 0;
        for ($i = 1; $i <= $cnt; $i++) {
            $lookup->FilterValues[] = $ar["v" . $i] ?? Post("v" . $i, "");
        }
        $lookup->SearchValue = $searchValue;
        $lookup->PageSize = $pageSize;
        $lookup->Offset = $offset;
        if ($userSelect != "") {
            $lookup->UserSelect = $userSelect;
        }
        if ($userFilter != "") {
            $lookup->UserFilter = $userFilter;
        }
        if ($userOrderBy != "") {
            $lookup->UserOrderBy = $userOrderBy;
        }
        return $lookup->toJson($this, !is_array($ar)); // Use settings from current page
    }
    public $FormClassName = "ew-form ew-add-form";
    public $IsModal = false;
    public $IsMobileOrModal = false;
    public $DbMasterFilter = "";
    public $DbDetailFilter = "";
    public $StartRecord;
    public $Priv = 0;
    public $OldRecordset;
    public $CopyRecord;

    /**
     * Page run
     *
     * @return void
     */
    public function run()
    {
        global $ExportType, $CustomExportType, $ExportFileName, $UserProfile, $Language, $Security, $CurrentForm,
            $SkipHeaderFooter;

        // Is modal
        $this->IsModal = Param("modal") == "1";
        $this->UseLayout = $this->UseLayout && !$this->IsModal;

        // Use layout
        $this->UseLayout = $this->UseLayout && ConvertToBool(Param("layout", true));

        // Create form object
        $CurrentForm = new HttpForm();
        $this->CurrentAction = Param("action"); // Set up current action
        $this->id->Visible = false;
        $this->vehicleID->setVisibility();
        $this->driverID->setVisibility();
        $this->cargoID->setVisibility();
        $this->passangerID->setVisibility();
        $this->order_date->setVisibility();
        $this->insertUserID->setVisibility();
        $this->insertWhen->setVisibility();
        $this->modifyUserID->Visible = false;
        $this->modifyWhen->Visible = false;
        $this->core_statusID->setVisibility();
        $this->core_languageID->setVisibility();
        $this->hideFieldsForAddEdit();

        // Set lookup cache
        if (!in_array($this->PageID, Config("LOOKUP_CACHE_PAGE_IDS"))) {
            $this->setUseLookupCache(false);
        }

        // Global Page Loading event (in userfn*.php)
        Page_Loading();

        // Page Load event
        if (method_exists($this, "pageLoad")) {
            $this->pageLoad();
        }

        // Set up lookup cache
        $this->setupLookupOptions($this->vehicleID);
        $this->setupLookupOptions($this->driverID);
        $this->setupLookupOptions($this->cargoID);
        $this->setupLookupOptions($this->passangerID);
        $this->setupLookupOptions($this->insertUserID);
        $this->setupLookupOptions($this->modifyUserID);
        $this->setupLookupOptions($this->core_statusID);
        $this->setupLookupOptions($this->core_languageID);

        // Load default values for add
        $this->loadDefaultValues();

        // Check modal
        if ($this->IsModal) {
            $SkipHeaderFooter = true;
        }
        $this->IsMobileOrModal = IsMobile() || $this->IsModal;
        $this->FormClassName = "ew-form ew-add-form";
        $postBack = false;

        // Set up current action
        if (IsApi()) {
            $this->CurrentAction = "insert"; // Add record directly
            $postBack = true;
        } elseif (Post("action") !== null) {
            $this->CurrentAction = Post("action"); // Get form action
            $this->setKey(Post($this->OldKeyName));
            $postBack = true;
        } else {
            // Load key values from QueryString
            if (($keyValue = Get("id") ?? Route("id")) !== null) {
                $this->id->setQueryStringValue($keyValue);
            }
            $this->OldKey = $this->getKey(true); // Get from CurrentValue
            $this->CopyRecord = !EmptyValue($this->OldKey);
            if ($this->CopyRecord) {
                $this->CurrentAction = "copy"; // Copy record
            } else {
                $this->CurrentAction = "show"; // Display blank record
            }
        }

        // Load old record / default values
        $loaded = $this->loadOldRecord();

        // Load form values
        if ($postBack) {
            $this->loadFormValues(); // Load form values
        }

        // Validate form if post back
        if ($postBack) {
            if (!$this->validateForm()) {
                $this->EventCancelled = true; // Event cancelled
                $this->restoreFormValues(); // Restore form values
                if (IsApi()) {
                    $this->terminate();
                    return;
                } else {
                    $this->CurrentAction = "show"; // Form error, reset action
                }
            }
        }

        // Perform current action
        switch ($this->CurrentAction) {
            case "copy": // Copy an existing record
                if (!$loaded) { // Record not loaded
                    if ($this->getFailureMessage() == "") {
                        $this->setFailureMessage($Language->phrase("NoRecord")); // No record found
                    }
                    $this->terminate("TransportList"); // No matching record, return to list
                    return;
                }
                break;
            case "insert": // Add new record
                $this->SendEmail = true; // Send email on add success
                if ($this->addRow($this->OldRecordset)) { // Add successful
                    if ($this->getSuccessMessage() == "" && Post("addopt") != "1") { // Skip success message for addopt (done in JavaScript)
                        $this->setSuccessMessage($Language->phrase("AddSuccess")); // Set up success message
                    }
                    $returnUrl = $this->getReturnUrl();
                    if (GetPageName($returnUrl) == "TransportList") {
                        $returnUrl = $this->addMasterUrl($returnUrl); // List page, return to List page with correct master key if necessary
                    } elseif (GetPageName($returnUrl) == "TransportView") {
                        $returnUrl = $this->getViewUrl(); // View page, return to View page with keyurl directly
                    }
                    if (IsApi()) { // Return to caller
                        $this->terminate(true);
                        return;
                    } else {
                        $this->terminate($returnUrl);
                        return;
                    }
                } elseif (IsApi()) { // API request, return
                    $this->terminate();
                    return;
                } else {
                    $this->EventCancelled = true; // Event cancelled
                    $this->restoreFormValues(); // Add failed, restore form values
                }
        }

        // Set up Breadcrumb
        $this->setupBreadcrumb();

        // Render row based on row type
        $this->RowType = ROWTYPE_ADD; // Render add type

        // Render row
        $this->resetAttributes();
        $this->renderRow();

        // Set LoginStatus / Page_Rendering / Page_Render
        if (!IsApi() && !$this->isTerminated()) {
            // Setup login status
            SetupLoginStatus();

            // Pass login status to client side
            SetClientVar("login", LoginStatus());

            // Global Page Rendering event (in userfn*.php)
            Page_Rendering();

            // Page Render event
            if (method_exists($this, "pageRender")) {
                $this->pageRender();
            }

            // Render search option
            if (method_exists($this, "renderSearchOptions")) {
                $this->renderSearchOptions();
            }
        }
    }

    // Get upload files
    protected function getUploadFiles()
    {
        global $CurrentForm, $Language;
    }

    // Load default values
    protected function loadDefaultValues()
    {
        $this->core_statusID->DefaultValue = 1;
    }

    // Load form values
    protected function loadFormValues()
    {
        // Load from form
        global $CurrentForm;
        $validate = !Config("SERVER_VALIDATE");

        // Check field name 'vehicleID' first before field var 'x_vehicleID'
        $val = $CurrentForm->hasValue("vehicleID") ? $CurrentForm->getValue("vehicleID") : $CurrentForm->getValue("x_vehicleID");
        if (!$this->vehicleID->IsDetailKey) {
            if (IsApi() && $val === null) {
                $this->vehicleID->Visible = false; // Disable update for API request
            } else {
                $this->vehicleID->setFormValue($val);
            }
        }

        // Check field name 'driverID' first before field var 'x_driverID'
        $val = $CurrentForm->hasValue("driverID") ? $CurrentForm->getValue("driverID") : $CurrentForm->getValue("x_driverID");
        if (!$this->driverID->IsDetailKey) {
            if (IsApi() && $val === null) {
                $this->driverID->Visible = false; // Disable update for API request
            } else {
                $this->driverID->setFormValue($val);
            }
        }

        // Check field name 'cargoID' first before field var 'x_cargoID'
        $val = $CurrentForm->hasValue("cargoID") ? $CurrentForm->getValue("cargoID") : $CurrentForm->getValue("x_cargoID");
        if (!$this->cargoID->IsDetailKey) {
            if (IsApi() && $val === null) {
                $this->cargoID->Visible = false; // Disable update for API request
            } else {
                $this->cargoID->setFormValue($val);
            }
        }

        // Check field name 'passangerID' first before field var 'x_passangerID'
        $val = $CurrentForm->hasValue("passangerID") ? $CurrentForm->getValue("passangerID") : $CurrentForm->getValue("x_passangerID");
        if (!$this->passangerID->IsDetailKey) {
            if (IsApi() && $val === null) {
                $this->passangerID->Visible = false; // Disable update for API request
            } else {
                $this->passangerID->setFormValue($val);
            }
        }

        // Check field name 'order_date' first before field var 'x_order_date'
        $val = $CurrentForm->hasValue("order_date") ? $CurrentForm->getValue("order_date") : $CurrentForm->getValue("x_order_date");
        if (!$this->order_date->IsDetailKey) {
            if (IsApi() && $val === null) {
                $this->order_date->Visible = false; // Disable update for API request
            } else {
                $this->order_date->setFormValue($val, true, $validate);
            }
            $this->order_date->CurrentValue = UnFormatDateTime($this->order_date->CurrentValue, $this->order_date->formatPattern());
        }

        // Check field name 'insertUserID' first before field var 'x_insertUserID'
        $val = $CurrentForm->hasValue("insertUserID") ? $CurrentForm->getValue("insertUserID") : $CurrentForm->getValue("x_insertUserID");
        if (!$this->insertUserID->IsDetailKey) {
            if (IsApi() && $val === null) {
                $this->insertUserID->Visible = false; // Disable update for API request
            } else {
                $this->insertUserID->setFormValue($val);
            }
        }

        // Check field name 'insertWhen' first before field var 'x_insertWhen'
        $val = $CurrentForm->hasValue("insertWhen") ? $CurrentForm->getValue("insertWhen") : $CurrentForm->getValue("x_insertWhen");
        if (!$this->insertWhen->IsDetailKey) {
            if (IsApi() && $val === null) {
                $this->insertWhen->Visible = false; // Disable update for API request
            } else {
                $this->insertWhen->setFormValue($val);
            }
            $this->insertWhen->CurrentValue = UnFormatDateTime($this->insertWhen->CurrentValue, $this->insertWhen->formatPattern());
        }

        // Check field name 'core_statusID' first before field var 'x_core_statusID'
        $val = $CurrentForm->hasValue("core_statusID") ? $CurrentForm->getValue("core_statusID") : $CurrentForm->getValue("x_core_statusID");
        if (!$this->core_statusID->IsDetailKey) {
            if (IsApi() && $val === null) {
                $this->core_statusID->Visible = false; // Disable update for API request
            } else {
                $this->core_statusID->setFormValue($val);
            }
        }

        // Check field name 'core_languageID' first before field var 'x_core_languageID'
        $val = $CurrentForm->hasValue("core_languageID") ? $CurrentForm->getValue("core_languageID") : $CurrentForm->getValue("x_core_languageID");
        if (!$this->core_languageID->IsDetailKey) {
            if (IsApi() && $val === null) {
                $this->core_languageID->Visible = false; // Disable update for API request
            } else {
                $this->core_languageID->setFormValue($val);
            }
        }

        // Check field name 'id' first before field var 'x_id'
        $val = $CurrentForm->hasValue("id") ? $CurrentForm->getValue("id") : $CurrentForm->getValue("x_id");
    }

    // Restore form values
    public function restoreFormValues()
    {
        global $CurrentForm;
        $this->vehicleID->CurrentValue = $this->vehicleID->FormValue;
        $this->driverID->CurrentValue = $this->driverID->FormValue;
        $this->cargoID->CurrentValue = $this->cargoID->FormValue;
        $this->passangerID->CurrentValue = $this->passangerID->FormValue;
        $this->order_date->CurrentValue = $this->order_date->FormValue;
        $this->order_date->CurrentValue = UnFormatDateTime($this->order_date->CurrentValue, $this->order_date->formatPattern());
        $this->insertUserID->CurrentValue = $this->insertUserID->FormValue;
        $this->insertWhen->CurrentValue = $this->insertWhen->FormValue;
        $this->insertWhen->CurrentValue = UnFormatDateTime($this->insertWhen->CurrentValue, $this->insertWhen->formatPattern());
        $this->core_statusID->CurrentValue = $this->core_statusID->FormValue;
        $this->core_languageID->CurrentValue = $this->core_languageID->FormValue;
    }

    /**
     * Load row based on key values
     *
     * @return void
     */
    public function loadRow()
    {
        global $Security, $Language;
        $filter = $this->getRecordFilter();

        // Call Row Selecting event
        $this->rowSelecting($filter);

        // Load SQL based on filter
        $this->CurrentFilter = $filter;
        $sql = $this->getCurrentSql();
        $conn = $this->getConnection();
        $res = false;
        $row = $conn->fetchAssociative($sql);
        if ($row) {
            $res = true;
            $this->loadRowValues($row); // Load row values
        }
        return $res;
    }

    /**
     * Load row values from recordset or record
     *
     * @param Recordset|array $rs Record
     * @return void
     */
    public function loadRowValues($rs = null)
    {
        if (is_array($rs)) {
            $row = $rs;
        } elseif ($rs && property_exists($rs, "fields")) { // Recordset
            $row = $rs->fields;
        } else {
            $row = $this->newRow();
        }
        if (!$row) {
            return;
        }

        // Call Row Selected event
        $this->rowSelected($row);
        $this->id->setDbValue($row['id']);
        $this->vehicleID->setDbValue($row['vehicleID']);
        $this->driverID->setDbValue($row['driverID']);
        $this->cargoID->setDbValue($row['cargoID']);
        $this->passangerID->setDbValue($row['passangerID']);
        $this->order_date->setDbValue($row['order_date']);
        $this->insertUserID->setDbValue($row['insertUserID']);
        $this->insertWhen->setDbValue($row['insertWhen']);
        $this->modifyUserID->setDbValue($row['modifyUserID']);
        $this->modifyWhen->setDbValue($row['modifyWhen']);
        $this->core_statusID->setDbValue($row['core_statusID']);
        $this->core_languageID->setDbValue($row['core_languageID']);
    }

    // Return a row with default values
    protected function newRow()
    {
        $row = [];
        $row['id'] = $this->id->DefaultValue;
        $row['vehicleID'] = $this->vehicleID->DefaultValue;
        $row['driverID'] = $this->driverID->DefaultValue;
        $row['cargoID'] = $this->cargoID->DefaultValue;
        $row['passangerID'] = $this->passangerID->DefaultValue;
        $row['order_date'] = $this->order_date->DefaultValue;
        $row['insertUserID'] = $this->insertUserID->DefaultValue;
        $row['insertWhen'] = $this->insertWhen->DefaultValue;
        $row['modifyUserID'] = $this->modifyUserID->DefaultValue;
        $row['modifyWhen'] = $this->modifyWhen->DefaultValue;
        $row['core_statusID'] = $this->core_statusID->DefaultValue;
        $row['core_languageID'] = $this->core_languageID->DefaultValue;
        return $row;
    }

    // Load old record
    protected function loadOldRecord()
    {
        // Load old record
        $this->OldRecordset = null;
        $validKey = $this->OldKey != "";
        if ($validKey) {
            $this->CurrentFilter = $this->getRecordFilter();
            $sql = $this->getCurrentSql();
            $conn = $this->getConnection();
            $this->OldRecordset = LoadRecordset($sql, $conn);
        }
        $this->loadRowValues($this->OldRecordset); // Load row values
        return $validKey;
    }

    // Render row values based on field settings
    public function renderRow()
    {
        global $Security, $Language, $CurrentLanguage;

        // Initialize URLs

        // Call Row_Rendering event
        $this->rowRendering();

        // Common render codes for all row types

        // id
        $this->id->RowCssClass = $this->IsMobileOrModal ? "row" : "";

        // vehicleID
        $this->vehicleID->RowCssClass = $this->IsMobileOrModal ? "row" : "";

        // driverID
        $this->driverID->RowCssClass = $this->IsMobileOrModal ? "row" : "";

        // cargoID
        $this->cargoID->RowCssClass = $this->IsMobileOrModal ? "row" : "";

        // passangerID
        $this->passangerID->RowCssClass = $this->IsMobileOrModal ? "row" : "";

        // order_date
        $this->order_date->RowCssClass = $this->IsMobileOrModal ? "row" : "";

        // insertUserID
        $this->insertUserID->RowCssClass = $this->IsMobileOrModal ? "row" : "";

        // insertWhen
        $this->insertWhen->RowCssClass = $this->IsMobileOrModal ? "row" : "";

        // modifyUserID
        $this->modifyUserID->RowCssClass = $this->IsMobileOrModal ? "row" : "";

        // modifyWhen
        $this->modifyWhen->RowCssClass = $this->IsMobileOrModal ? "row" : "";

        // core_statusID
        $this->core_statusID->RowCssClass = $this->IsMobileOrModal ? "row" : "";

        // core_languageID
        $this->core_languageID->RowCssClass = $this->IsMobileOrModal ? "row" : "";

        // View row
        if ($this->RowType == ROWTYPE_VIEW) {
            // id
            $this->id->ViewValue = $this->id->CurrentValue;
            $this->id->ViewCustomAttributes = "";

            // vehicleID
            $curVal = strval($this->vehicleID->CurrentValue);
            if ($curVal != "") {
                $this->vehicleID->ViewValue = $this->vehicleID->lookupCacheOption($curVal);
                if ($this->vehicleID->ViewValue === null) { // Lookup from database
                    $filterWrk = "`id`" . SearchString("=", $curVal, DATATYPE_NUMBER, "");
                    $sqlWrk = $this->vehicleID->Lookup->getSql(false, $filterWrk, '', $this, true, true);
                    $conn = Conn();
                    $config = $conn->getConfiguration();
                    $config->setResultCacheImpl($this->Cache);
                    $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                    $ari = count($rswrk);
                    if ($ari > 0) { // Lookup values found
                        $arwrk = $this->vehicleID->Lookup->renderViewRow($rswrk[0]);
                        $this->vehicleID->ViewValue = $this->vehicleID->displayValue($arwrk);
                    } else {
                        $this->vehicleID->ViewValue = FormatNumber($this->vehicleID->CurrentValue, $this->vehicleID->formatPattern());
                    }
                }
            } else {
                $this->vehicleID->ViewValue = null;
            }
            $this->vehicleID->ViewCustomAttributes = "";

            // driverID
            $curVal = strval($this->driverID->CurrentValue);
            if ($curVal != "") {
                $this->driverID->ViewValue = $this->driverID->lookupCacheOption($curVal);
                if ($this->driverID->ViewValue === null) { // Lookup from database
                    $filterWrk = "`id`" . SearchString("=", $curVal, DATATYPE_NUMBER, "");
                    $sqlWrk = $this->driverID->Lookup->getSql(false, $filterWrk, '', $this, true, true);
                    $conn = Conn();
                    $config = $conn->getConfiguration();
                    $config->setResultCacheImpl($this->Cache);
                    $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                    $ari = count($rswrk);
                    if ($ari > 0) { // Lookup values found
                        $arwrk = $this->driverID->Lookup->renderViewRow($rswrk[0]);
                        $this->driverID->ViewValue = $this->driverID->displayValue($arwrk);
                    } else {
                        $this->driverID->ViewValue = FormatNumber($this->driverID->CurrentValue, $this->driverID->formatPattern());
                    }
                }
            } else {
                $this->driverID->ViewValue = null;
            }
            $this->driverID->ViewCustomAttributes = "";

            // cargoID
            $curVal = strval($this->cargoID->CurrentValue);
            if ($curVal != "") {
                $this->cargoID->ViewValue = $this->cargoID->lookupCacheOption($curVal);
                if ($this->cargoID->ViewValue === null) { // Lookup from database
                    $filterWrk = "`id`" . SearchString("=", $curVal, DATATYPE_NUMBER, "");
                    $sqlWrk = $this->cargoID->Lookup->getSql(false, $filterWrk, '', $this, true, true);
                    $conn = Conn();
                    $config = $conn->getConfiguration();
                    $config->setResultCacheImpl($this->Cache);
                    $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                    $ari = count($rswrk);
                    if ($ari > 0) { // Lookup values found
                        $arwrk = $this->cargoID->Lookup->renderViewRow($rswrk[0]);
                        $this->cargoID->ViewValue = $this->cargoID->displayValue($arwrk);
                    } else {
                        $this->cargoID->ViewValue = FormatNumber($this->cargoID->CurrentValue, $this->cargoID->formatPattern());
                    }
                }
            } else {
                $this->cargoID->ViewValue = null;
            }
            $this->cargoID->ViewCustomAttributes = "";

            // passangerID
            $curVal = strval($this->passangerID->CurrentValue);
            if ($curVal != "") {
                $this->passangerID->ViewValue = $this->passangerID->lookupCacheOption($curVal);
                if ($this->passangerID->ViewValue === null) { // Lookup from database
                    $filterWrk = "`id`" . SearchString("=", $curVal, DATATYPE_NUMBER, "");
                    $sqlWrk = $this->passangerID->Lookup->getSql(false, $filterWrk, '', $this, true, true);
                    $conn = Conn();
                    $config = $conn->getConfiguration();
                    $config->setResultCacheImpl($this->Cache);
                    $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                    $ari = count($rswrk);
                    if ($ari > 0) { // Lookup values found
                        $arwrk = $this->passangerID->Lookup->renderViewRow($rswrk[0]);
                        $this->passangerID->ViewValue = $this->passangerID->displayValue($arwrk);
                    } else {
                        $this->passangerID->ViewValue = FormatNumber($this->passangerID->CurrentValue, $this->passangerID->formatPattern());
                    }
                }
            } else {
                $this->passangerID->ViewValue = null;
            }
            $this->passangerID->ViewCustomAttributes = "";

            // order_date
            $this->order_date->ViewValue = $this->order_date->CurrentValue;
            $this->order_date->ViewValue = FormatDateTime($this->order_date->ViewValue, $this->order_date->formatPattern());
            $this->order_date->ViewCustomAttributes = "";

            // insertUserID
            $curVal = strval($this->insertUserID->CurrentValue);
            if ($curVal != "") {
                $this->insertUserID->ViewValue = $this->insertUserID->lookupCacheOption($curVal);
                if ($this->insertUserID->ViewValue === null) { // Lookup from database
                    $filterWrk = "`id`" . SearchString("=", $curVal, DATATYPE_NUMBER, "");
                    $sqlWrk = $this->insertUserID->Lookup->getSql(false, $filterWrk, '', $this, true, true);
                    $conn = Conn();
                    $config = $conn->getConfiguration();
                    $config->setResultCacheImpl($this->Cache);
                    $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                    $ari = count($rswrk);
                    if ($ari > 0) { // Lookup values found
                        $arwrk = $this->insertUserID->Lookup->renderViewRow($rswrk[0]);
                        $this->insertUserID->ViewValue = $this->insertUserID->displayValue($arwrk);
                    } else {
                        $this->insertUserID->ViewValue = FormatNumber($this->insertUserID->CurrentValue, $this->insertUserID->formatPattern());
                    }
                }
            } else {
                $this->insertUserID->ViewValue = null;
            }
            $this->insertUserID->ViewCustomAttributes = "";

            // insertWhen
            $this->insertWhen->ViewValue = $this->insertWhen->CurrentValue;
            $this->insertWhen->ViewValue = FormatDateTime($this->insertWhen->ViewValue, $this->insertWhen->formatPattern());
            $this->insertWhen->ViewCustomAttributes = "";

            // modifyUserID
            $curVal = strval($this->modifyUserID->CurrentValue);
            if ($curVal != "") {
                $this->modifyUserID->ViewValue = $this->modifyUserID->lookupCacheOption($curVal);
                if ($this->modifyUserID->ViewValue === null) { // Lookup from database
                    $filterWrk = "`id`" . SearchString("=", $curVal, DATATYPE_NUMBER, "");
                    $sqlWrk = $this->modifyUserID->Lookup->getSql(false, $filterWrk, '', $this, true, true);
                    $conn = Conn();
                    $config = $conn->getConfiguration();
                    $config->setResultCacheImpl($this->Cache);
                    $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                    $ari = count($rswrk);
                    if ($ari > 0) { // Lookup values found
                        $arwrk = $this->modifyUserID->Lookup->renderViewRow($rswrk[0]);
                        $this->modifyUserID->ViewValue = $this->modifyUserID->displayValue($arwrk);
                    } else {
                        $this->modifyUserID->ViewValue = FormatNumber($this->modifyUserID->CurrentValue, $this->modifyUserID->formatPattern());
                    }
                }
            } else {
                $this->modifyUserID->ViewValue = null;
            }
            $this->modifyUserID->ViewCustomAttributes = "";

            // modifyWhen
            $this->modifyWhen->ViewValue = $this->modifyWhen->CurrentValue;
            $this->modifyWhen->ViewValue = FormatDateTime($this->modifyWhen->ViewValue, $this->modifyWhen->formatPattern());
            $this->modifyWhen->ViewCustomAttributes = "";

            // core_statusID
            $curVal = strval($this->core_statusID->CurrentValue);
            if ($curVal != "") {
                $this->core_statusID->ViewValue = $this->core_statusID->lookupCacheOption($curVal);
                if ($this->core_statusID->ViewValue === null) { // Lookup from database
                    $filterWrk = "`id`" . SearchString("=", $curVal, DATATYPE_NUMBER, "");
                    $sqlWrk = $this->core_statusID->Lookup->getSql(false, $filterWrk, '', $this, true, true);
                    $conn = Conn();
                    $config = $conn->getConfiguration();
                    $config->setResultCacheImpl($this->Cache);
                    $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                    $ari = count($rswrk);
                    if ($ari > 0) { // Lookup values found
                        $arwrk = $this->core_statusID->Lookup->renderViewRow($rswrk[0]);
                        $this->core_statusID->ViewValue = $this->core_statusID->displayValue($arwrk);
                    } else {
                        $this->core_statusID->ViewValue = FormatNumber($this->core_statusID->CurrentValue, $this->core_statusID->formatPattern());
                    }
                }
            } else {
                $this->core_statusID->ViewValue = null;
            }
            $this->core_statusID->ViewCustomAttributes = "";

            // core_languageID
            $curVal = strval($this->core_languageID->CurrentValue);
            if ($curVal != "") {
                $this->core_languageID->ViewValue = $this->core_languageID->lookupCacheOption($curVal);
                if ($this->core_languageID->ViewValue === null) { // Lookup from database
                    $filterWrk = "`id`" . SearchString("=", $curVal, DATATYPE_STRING, "");
                    $sqlWrk = $this->core_languageID->Lookup->getSql(false, $filterWrk, '', $this, true, true);
                    $conn = Conn();
                    $config = $conn->getConfiguration();
                    $config->setResultCacheImpl($this->Cache);
                    $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                    $ari = count($rswrk);
                    if ($ari > 0) { // Lookup values found
                        $arwrk = $this->core_languageID->Lookup->renderViewRow($rswrk[0]);
                        $this->core_languageID->ViewValue = $this->core_languageID->displayValue($arwrk);
                    } else {
                        $this->core_languageID->ViewValue = $this->core_languageID->CurrentValue;
                    }
                }
            } else {
                $this->core_languageID->ViewValue = null;
            }
            $this->core_languageID->ViewCustomAttributes = "";

            // vehicleID
            $this->vehicleID->LinkCustomAttributes = "";
            $this->vehicleID->HrefValue = "";

            // driverID
            $this->driverID->LinkCustomAttributes = "";
            $this->driverID->HrefValue = "";

            // cargoID
            $this->cargoID->LinkCustomAttributes = "";
            $this->cargoID->HrefValue = "";

            // passangerID
            $this->passangerID->LinkCustomAttributes = "";
            $this->passangerID->HrefValue = "";

            // order_date
            $this->order_date->LinkCustomAttributes = "";
            $this->order_date->HrefValue = "";

            // insertUserID
            $this->insertUserID->LinkCustomAttributes = "";
            $this->insertUserID->HrefValue = "";

            // insertWhen
            $this->insertWhen->LinkCustomAttributes = "";
            $this->insertWhen->HrefValue = "";

            // core_statusID
            $this->core_statusID->LinkCustomAttributes = "";
            $this->core_statusID->HrefValue = "";

            // core_languageID
            $this->core_languageID->LinkCustomAttributes = "";
            $this->core_languageID->HrefValue = "";
        } elseif ($this->RowType == ROWTYPE_ADD) {
            // vehicleID
            $this->vehicleID->setupEditAttributes();
            $this->vehicleID->EditCustomAttributes = "";
            $curVal = trim(strval($this->vehicleID->CurrentValue));
            if ($curVal != "") {
                $this->vehicleID->ViewValue = $this->vehicleID->lookupCacheOption($curVal);
            } else {
                $this->vehicleID->ViewValue = $this->vehicleID->Lookup !== null && is_array($this->vehicleID->lookupOptions()) ? $curVal : null;
            }
            if ($this->vehicleID->ViewValue !== null) { // Load from cache
                $this->vehicleID->EditValue = array_values($this->vehicleID->lookupOptions());
            } else { // Lookup from database
                if ($curVal == "") {
                    $filterWrk = "0=1";
                } else {
                    $filterWrk = "`id`" . SearchString("=", $this->vehicleID->CurrentValue, DATATYPE_NUMBER, "");
                }
                $sqlWrk = $this->vehicleID->Lookup->getSql(true, $filterWrk, '', $this, false, true);
                $conn = Conn();
                $config = $conn->getConfiguration();
                $config->setResultCacheImpl($this->Cache);
                $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                $ari = count($rswrk);
                $arwrk = $rswrk;
                foreach ($arwrk as &$row) {
                    $row = $this->vehicleID->Lookup->renderViewRow($row);
                }
                $this->vehicleID->EditValue = $arwrk;
            }
            $this->vehicleID->PlaceHolder = RemoveHtml($this->vehicleID->caption());

            // driverID
            $this->driverID->setupEditAttributes();
            $this->driverID->EditCustomAttributes = "";
            $curVal = trim(strval($this->driverID->CurrentValue));
            if ($curVal != "") {
                $this->driverID->ViewValue = $this->driverID->lookupCacheOption($curVal);
            } else {
                $this->driverID->ViewValue = $this->driverID->Lookup !== null && is_array($this->driverID->lookupOptions()) ? $curVal : null;
            }
            if ($this->driverID->ViewValue !== null) { // Load from cache
                $this->driverID->EditValue = array_values($this->driverID->lookupOptions());
            } else { // Lookup from database
                if ($curVal == "") {
                    $filterWrk = "0=1";
                } else {
                    $filterWrk = "`id`" . SearchString("=", $this->driverID->CurrentValue, DATATYPE_NUMBER, "");
                }
                $sqlWrk = $this->driverID->Lookup->getSql(true, $filterWrk, '', $this, false, true);
                $conn = Conn();
                $config = $conn->getConfiguration();
                $config->setResultCacheImpl($this->Cache);
                $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                $ari = count($rswrk);
                $arwrk = $rswrk;
                foreach ($arwrk as &$row) {
                    $row = $this->driverID->Lookup->renderViewRow($row);
                }
                $this->driverID->EditValue = $arwrk;
            }
            $this->driverID->PlaceHolder = RemoveHtml($this->driverID->caption());

            // cargoID
            $this->cargoID->setupEditAttributes();
            $this->cargoID->EditCustomAttributes = "";
            $curVal = trim(strval($this->cargoID->CurrentValue));
            if ($curVal != "") {
                $this->cargoID->ViewValue = $this->cargoID->lookupCacheOption($curVal);
            } else {
                $this->cargoID->ViewValue = $this->cargoID->Lookup !== null && is_array($this->cargoID->lookupOptions()) ? $curVal : null;
            }
            if ($this->cargoID->ViewValue !== null) { // Load from cache
                $this->cargoID->EditValue = array_values($this->cargoID->lookupOptions());
            } else { // Lookup from database
                if ($curVal == "") {
                    $filterWrk = "0=1";
                } else {
                    $filterWrk = "`id`" . SearchString("=", $this->cargoID->CurrentValue, DATATYPE_NUMBER, "");
                }
                $sqlWrk = $this->cargoID->Lookup->getSql(true, $filterWrk, '', $this, false, true);
                $conn = Conn();
                $config = $conn->getConfiguration();
                $config->setResultCacheImpl($this->Cache);
                $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                $ari = count($rswrk);
                $arwrk = $rswrk;
                $this->cargoID->EditValue = $arwrk;
            }
            $this->cargoID->PlaceHolder = RemoveHtml($this->cargoID->caption());

            // passangerID
            $this->passangerID->setupEditAttributes();
            $this->passangerID->EditCustomAttributes = "";
            $curVal = trim(strval($this->passangerID->CurrentValue));
            if ($curVal != "") {
                $this->passangerID->ViewValue = $this->passangerID->lookupCacheOption($curVal);
            } else {
                $this->passangerID->ViewValue = $this->passangerID->Lookup !== null && is_array($this->passangerID->lookupOptions()) ? $curVal : null;
            }
            if ($this->passangerID->ViewValue !== null) { // Load from cache
                $this->passangerID->EditValue = array_values($this->passangerID->lookupOptions());
            } else { // Lookup from database
                if ($curVal == "") {
                    $filterWrk = "0=1";
                } else {
                    $filterWrk = "`id`" . SearchString("=", $this->passangerID->CurrentValue, DATATYPE_NUMBER, "");
                }
                $sqlWrk = $this->passangerID->Lookup->getSql(true, $filterWrk, '', $this, false, true);
                $conn = Conn();
                $config = $conn->getConfiguration();
                $config->setResultCacheImpl($this->Cache);
                $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                $ari = count($rswrk);
                $arwrk = $rswrk;
                foreach ($arwrk as &$row) {
                    $row = $this->passangerID->Lookup->renderViewRow($row);
                }
                $this->passangerID->EditValue = $arwrk;
            }
            $this->passangerID->PlaceHolder = RemoveHtml($this->passangerID->caption());

            // order_date
            $this->order_date->setupEditAttributes();
            $this->order_date->EditCustomAttributes = "";
            $this->order_date->EditValue = HtmlEncode(FormatDateTime($this->order_date->CurrentValue, $this->order_date->formatPattern()));
            $this->order_date->PlaceHolder = RemoveHtml($this->order_date->caption());

            // insertUserID

            // insertWhen

            // core_statusID
            $this->core_statusID->setupEditAttributes();
            $this->core_statusID->EditCustomAttributes = "";
            $curVal = trim(strval($this->core_statusID->CurrentValue));
            if ($curVal != "") {
                $this->core_statusID->ViewValue = $this->core_statusID->lookupCacheOption($curVal);
            } else {
                $this->core_statusID->ViewValue = $this->core_statusID->Lookup !== null && is_array($this->core_statusID->lookupOptions()) ? $curVal : null;
            }
            if ($this->core_statusID->ViewValue !== null) { // Load from cache
                $this->core_statusID->EditValue = array_values($this->core_statusID->lookupOptions());
            } else { // Lookup from database
                if ($curVal == "") {
                    $filterWrk = "0=1";
                } else {
                    $filterWrk = "`id`" . SearchString("=", $this->core_statusID->CurrentValue, DATATYPE_NUMBER, "");
                }
                $sqlWrk = $this->core_statusID->Lookup->getSql(true, $filterWrk, '', $this, false, true);
                $conn = Conn();
                $config = $conn->getConfiguration();
                $config->setResultCacheImpl($this->Cache);
                $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                $ari = count($rswrk);
                $arwrk = $rswrk;
                $this->core_statusID->EditValue = $arwrk;
            }
            $this->core_statusID->PlaceHolder = RemoveHtml($this->core_statusID->caption());

            // core_languageID
            $this->core_languageID->setupEditAttributes();
            $this->core_languageID->EditCustomAttributes = "";
            $curVal = trim(strval($this->core_languageID->CurrentValue));
            if ($curVal != "") {
                $this->core_languageID->ViewValue = $this->core_languageID->lookupCacheOption($curVal);
            } else {
                $this->core_languageID->ViewValue = $this->core_languageID->Lookup !== null && is_array($this->core_languageID->lookupOptions()) ? $curVal : null;
            }
            if ($this->core_languageID->ViewValue !== null) { // Load from cache
                $this->core_languageID->EditValue = array_values($this->core_languageID->lookupOptions());
            } else { // Lookup from database
                if ($curVal == "") {
                    $filterWrk = "0=1";
                } else {
                    $filterWrk = "`id`" . SearchString("=", $this->core_languageID->CurrentValue, DATATYPE_STRING, "");
                }
                $sqlWrk = $this->core_languageID->Lookup->getSql(true, $filterWrk, '', $this, false, true);
                $conn = Conn();
                $config = $conn->getConfiguration();
                $config->setResultCacheImpl($this->Cache);
                $rswrk = $conn->executeCacheQuery($sqlWrk, [], [], $this->CacheProfile)->fetchAll();
                $ari = count($rswrk);
                $arwrk = $rswrk;
                $this->core_languageID->EditValue = $arwrk;
            }
            $this->core_languageID->PlaceHolder = RemoveHtml($this->core_languageID->caption());

            // Add refer script

            // vehicleID
            $this->vehicleID->LinkCustomAttributes = "";
            $this->vehicleID->HrefValue = "";

            // driverID
            $this->driverID->LinkCustomAttributes = "";
            $this->driverID->HrefValue = "";

            // cargoID
            $this->cargoID->LinkCustomAttributes = "";
            $this->cargoID->HrefValue = "";

            // passangerID
            $this->passangerID->LinkCustomAttributes = "";
            $this->passangerID->HrefValue = "";

            // order_date
            $this->order_date->LinkCustomAttributes = "";
            $this->order_date->HrefValue = "";

            // insertUserID
            $this->insertUserID->LinkCustomAttributes = "";
            $this->insertUserID->HrefValue = "";

            // insertWhen
            $this->insertWhen->LinkCustomAttributes = "";
            $this->insertWhen->HrefValue = "";

            // core_statusID
            $this->core_statusID->LinkCustomAttributes = "";
            $this->core_statusID->HrefValue = "";

            // core_languageID
            $this->core_languageID->LinkCustomAttributes = "";
            $this->core_languageID->HrefValue = "";
        }
        if ($this->RowType == ROWTYPE_ADD || $this->RowType == ROWTYPE_EDIT || $this->RowType == ROWTYPE_SEARCH) { // Add/Edit/Search row
            $this->setupFieldTitles();
        }

        // Call Row Rendered event
        if ($this->RowType != ROWTYPE_AGGREGATEINIT) {
            $this->rowRendered();
        }
    }

    // Validate form
    protected function validateForm()
    {
        global $Language;

        // Check if validation required
        if (!Config("SERVER_VALIDATE")) {
            return true;
        }
        $validateForm = true;
        if ($this->vehicleID->Required) {
            if (!$this->vehicleID->IsDetailKey && EmptyValue($this->vehicleID->FormValue)) {
                $this->vehicleID->addErrorMessage(str_replace("%s", $this->vehicleID->caption(), $this->vehicleID->RequiredErrorMessage));
            }
        }
        if ($this->driverID->Required) {
            if (!$this->driverID->IsDetailKey && EmptyValue($this->driverID->FormValue)) {
                $this->driverID->addErrorMessage(str_replace("%s", $this->driverID->caption(), $this->driverID->RequiredErrorMessage));
            }
        }
        if ($this->cargoID->Required) {
            if (!$this->cargoID->IsDetailKey && EmptyValue($this->cargoID->FormValue)) {
                $this->cargoID->addErrorMessage(str_replace("%s", $this->cargoID->caption(), $this->cargoID->RequiredErrorMessage));
            }
        }
        if ($this->passangerID->Required) {
            if (!$this->passangerID->IsDetailKey && EmptyValue($this->passangerID->FormValue)) {
                $this->passangerID->addErrorMessage(str_replace("%s", $this->passangerID->caption(), $this->passangerID->RequiredErrorMessage));
            }
        }
        if ($this->order_date->Required) {
            if (!$this->order_date->IsDetailKey && EmptyValue($this->order_date->FormValue)) {
                $this->order_date->addErrorMessage(str_replace("%s", $this->order_date->caption(), $this->order_date->RequiredErrorMessage));
            }
        }
        if (!CheckDate($this->order_date->FormValue, $this->order_date->formatPattern())) {
            $this->order_date->addErrorMessage($this->order_date->getErrorMessage(false));
        }
        if ($this->insertUserID->Required) {
            if (!$this->insertUserID->IsDetailKey && EmptyValue($this->insertUserID->FormValue)) {
                $this->insertUserID->addErrorMessage(str_replace("%s", $this->insertUserID->caption(), $this->insertUserID->RequiredErrorMessage));
            }
        }
        if ($this->insertWhen->Required) {
            if (!$this->insertWhen->IsDetailKey && EmptyValue($this->insertWhen->FormValue)) {
                $this->insertWhen->addErrorMessage(str_replace("%s", $this->insertWhen->caption(), $this->insertWhen->RequiredErrorMessage));
            }
        }
        if ($this->core_statusID->Required) {
            if (!$this->core_statusID->IsDetailKey && EmptyValue($this->core_statusID->FormValue)) {
                $this->core_statusID->addErrorMessage(str_replace("%s", $this->core_statusID->caption(), $this->core_statusID->RequiredErrorMessage));
            }
        }
        if ($this->core_languageID->Required) {
            if (!$this->core_languageID->IsDetailKey && EmptyValue($this->core_languageID->FormValue)) {
                $this->core_languageID->addErrorMessage(str_replace("%s", $this->core_languageID->caption(), $this->core_languageID->RequiredErrorMessage));
            }
        }

        // Return validate result
        $validateForm = $validateForm && !$this->hasInvalidFields();

        // Call Form_CustomValidate event
        $formCustomError = "";
        $validateForm = $validateForm && $this->formCustomValidate($formCustomError);
        if ($formCustomError != "") {
            $this->setFailureMessage($formCustomError);
        }
        return $validateForm;
    }

    // Add record
    protected function addRow($rsold = null)
    {
        global $Language, $Security;

        // Set new row
        $rsnew = [];

        // vehicleID
        $this->vehicleID->setDbValueDef($rsnew, $this->vehicleID->CurrentValue, null, false);

        // driverID
        $this->driverID->setDbValueDef($rsnew, $this->driverID->CurrentValue, null, false);

        // cargoID
        $this->cargoID->setDbValueDef($rsnew, $this->cargoID->CurrentValue, null, false);

        // passangerID
        $this->passangerID->setDbValueDef($rsnew, $this->passangerID->CurrentValue, null, false);

        // order_date
        $this->order_date->setDbValueDef($rsnew, UnFormatDateTime($this->order_date->CurrentValue, $this->order_date->formatPattern()), null, false);

        // insertUserID
        $this->insertUserID->CurrentValue = CurrentUserID();
        $this->insertUserID->setDbValueDef($rsnew, $this->insertUserID->CurrentValue, null);

        // insertWhen
        $this->insertWhen->CurrentValue = CurrentDateTime();
        $this->insertWhen->setDbValueDef($rsnew, $this->insertWhen->CurrentValue, null);

        // core_statusID
        $this->core_statusID->setDbValueDef($rsnew, $this->core_statusID->CurrentValue, null, strval($this->core_statusID->CurrentValue) == "");

        // core_languageID
        $this->core_languageID->setDbValueDef($rsnew, $this->core_languageID->CurrentValue, "", false);

        // Update current values
        $this->setCurrentValues($rsnew);
        $conn = $this->getConnection();

        // Load db values from old row
        $this->loadDbValues($rsold);
        if ($rsold) {
        }

        // Call Row Inserting event
        $insertRow = $this->rowInserting($rsold, $rsnew);
        if ($insertRow) {
            $addRow = $this->insert($rsnew);
            if ($addRow) {
            }
        } else {
            if ($this->getSuccessMessage() != "" || $this->getFailureMessage() != "") {
                // Use the message, do nothing
            } elseif ($this->CancelMessage != "") {
                $this->setFailureMessage($this->CancelMessage);
                $this->CancelMessage = "";
            } else {
                $this->setFailureMessage($Language->phrase("InsertCancelled"));
            }
            $addRow = false;
        }
        if ($addRow) {
            // Call Row Inserted event
            $this->rowInserted($rsold, $rsnew);
        }

        // Clean upload path if any
        if ($addRow) {
        }

        // Write JSON for API request
        if (IsApi() && $addRow) {
            $row = $this->getRecordsFromRecordset([$rsnew], true);
            WriteJson(["success" => true, $this->TableVar => $row]);
        }
        return $addRow;
    }

    // Set up Breadcrumb
    protected function setupBreadcrumb()
    {
        global $Breadcrumb, $Language;
        $Breadcrumb = new Breadcrumb("index");
        $url = CurrentUrl();
        $Breadcrumb->add("list", $this->TableVar, $this->addMasterUrl("TransportList"), "", $this->TableVar, true);
        $pageId = ($this->isCopy()) ? "Copy" : "Add";
        $Breadcrumb->add("add", $pageId, $url);
    }

    // Setup lookup options
    public function setupLookupOptions($fld)
    {
        if ($fld->Lookup !== null && $fld->Lookup->Options === null) {
            // Get default connection and filter
            $conn = $this->getConnection();
            $lookupFilter = "";

            // No need to check any more
            $fld->Lookup->Options = [];

            // Set up lookup SQL and connection
            switch ($fld->FieldVar) {
                case "x_vehicleID":
                    break;
                case "x_driverID":
                    break;
                case "x_cargoID":
                    break;
                case "x_passangerID":
                    break;
                case "x_insertUserID":
                    break;
                case "x_modifyUserID":
                    break;
                case "x_core_statusID":
                    break;
                case "x_core_languageID":
                    break;
                default:
                    $lookupFilter = "";
                    break;
            }

            // Always call to Lookup->getSql so that user can setup Lookup->Options in Lookup_Selecting server event
            $sql = $fld->Lookup->getSql(false, "", $lookupFilter, $this);

            // Set up lookup cache
            if (!$fld->hasLookupOptions() && $fld->UseLookupCache && $sql != "" && count($fld->Lookup->Options) == 0) {
                $totalCnt = $this->getRecordCount($sql, $conn);
                if ($totalCnt > $fld->LookupCacheCount) { // Total count > cache count, do not cache
                    return;
                }
                $rows = $conn->executeQuery($sql)->fetchAll();
                $ar = [];
                foreach ($rows as $row) {
                    $row = $fld->Lookup->renderViewRow($row, Container($fld->Lookup->LinkTable));
                    $ar[strval($row["lf"])] = $row;
                }
                $fld->Lookup->Options = $ar;
            }
        }
    }

    // Page Load event
    public function pageLoad()
    {
        //Log("Page Load");
    }

    // Page Unload event
    public function pageUnload()
    {
        //Log("Page Unload");
    }

    // Page Redirecting event
    public function pageRedirecting(&$url)
    {
        // Example:
        //$url = "your URL";
    }

    // Message Showing event
    // $type = ''|'success'|'failure'|'warning'
    public function messageShowing(&$msg, $type)
    {
        if ($type == 'success') {
            //$msg = "your success message";
        } elseif ($type == 'failure') {
            //$msg = "your failure message";
        } elseif ($type == 'warning') {
            //$msg = "your warning message";
        } else {
            //$msg = "your message";
        }
    }

    // Page Render event
    public function pageRender()
    {
        //Log("Page Render");
    }

    // Page Data Rendering event
    public function pageDataRendering(&$header)
    {
        // Example:
        //$header = "your header";
    }

    // Page Data Rendered event
    public function pageDataRendered(&$footer)
    {
        // Example:
        //$footer = "your footer";
    }

    // Form Custom Validate event
    public function formCustomValidate(&$customError)
    {
        // Return error message in $customError
        return true;
    }
}