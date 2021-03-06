<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package Piwik_ImageGraph
 */

/**
 * The ImageGraph.get API call lets you generate beautiful static PNG Graphs for any existing Piwik report.
 * Supported graph types are: line plot, 2D/3D pie chart and vertical bar chart.
 *
 * A few notes about some of the parameters available:<br/>
 * - $graphType defines the type of graph plotted, accepted values are: 'evolution', 'verticalBar', 'pie' and '3dPie'<br/>
 * - $colors accepts a comma delimited list of colors that will overwrite the default Piwik colors <br/>
 * - you can also customize the width, height, font size, metric being plotted (in case the data contains multiple columns/metrics).
 *
 * See also <a href='http://piwik.org/docs/analytics-api/metadata/#toc-static-image-graphs'>How to embed static Image Graphs?</a> for more information.
 *
 * @package Piwik_ImageGraph
 */
class Piwik_ImageGraph_API
{
    const FILENAME_KEY = 'filename';
    const TRUNCATE_KEY = 'truncate';
    const WIDTH_KEY = 'width';
    const HEIGHT_KEY = 'height';
    const MAX_WIDTH = 2048;
    const MAX_HEIGHT = 2048;

    static private $DEFAULT_PARAMETERS = array(
        Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_BASIC_LINE     => array(
            self::FILENAME_KEY => 'BasicLine',
            self::TRUNCATE_KEY => 6,
            self::WIDTH_KEY    => 1044,
            self::HEIGHT_KEY   => 290,
        ),
        Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_VERTICAL_BAR   => array(
            self::FILENAME_KEY => 'BasicBar',
            self::TRUNCATE_KEY => 6,
            self::WIDTH_KEY    => 1044,
            self::HEIGHT_KEY   => 290,
        ),
        Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_HORIZONTAL_BAR => array(
            self::FILENAME_KEY => 'HorizontalBar',
            self::TRUNCATE_KEY => null, // horizontal bar graphs are dynamically truncated
            self::WIDTH_KEY    => 800,
            self::HEIGHT_KEY   => 290,
        ),
        Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_3D_PIE         => array(
            self::FILENAME_KEY => '3DPie',
            self::TRUNCATE_KEY => 5,
            self::WIDTH_KEY    => 1044,
            self::HEIGHT_KEY   => 290,
        ),
        Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_BASIC_PIE      => array(
            self::FILENAME_KEY => 'BasicPie',
            self::TRUNCATE_KEY => 5,
            self::WIDTH_KEY    => 1044,
            self::HEIGHT_KEY   => 290,
        ),
    );

    static private $DEFAULT_GRAPH_TYPE_OVERRIDE = array(
        'UserSettings_getPlugin'  => array(
            false // override if !$isMultiplePeriod
            => Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_HORIZONTAL_BAR,
        ),
        'Referers_getRefererType' => array(
            false // override if !$isMultiplePeriod
            => Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_HORIZONTAL_BAR,
        ),
    );

    const GRAPH_OUTPUT_INLINE = 0;
    const GRAPH_OUTPUT_FILE = 1;
    const GRAPH_OUTPUT_PHP = 2;

    const DEFAULT_ORDINATE_METRIC = 'nb_visits';
    const FONT_DIR = '/plugins/ImageGraph/fonts/';
    const DEFAULT_FONT = 'tahoma.ttf';
    const UNICODE_FONT = 'unifont.ttf';
    const DEFAULT_FONT_SIZE = 9;
    const DEFAULT_LEGEND_FONT_SIZE_OFFSET = 2;

    // number of row evolutions to plot when no labels are specified, can be overridden using &filter_limit
    const DEFAULT_NB_ROW_EVOLUTIONS = 5;
    const MAX_NB_ROW_LABELS = 10;

    static private $instance = null;

    /**
     * @return Piwik_ImageGraph_API
     */
    static public function getInstance()
    {
        if (self::$instance == null) {
            $c = __CLASS__;
            self::$instance = new $c();
        }
        return self::$instance;
    }

    public function get($idSite, $period, $date, $apiModule, $apiAction, $graphType = false,
                        $outputType = Piwik_ImageGraph_API::GRAPH_OUTPUT_INLINE, $columns = false, $labels = false, $showLegend = true,
                        $width = false, $height = false, $fontSize = Piwik_ImageGraph_API::DEFAULT_FONT_SIZE, $legendFontSize = false,
                        $aliasedGraph = true, $idGoal = false, $colors = false, $idSubtable = false, $legendAppendMetric = true)
    {
        Piwik::checkUserHasViewAccess($idSite);

        // Health check - should we also test for GD2 only?
        if (!Piwik::isGdExtensionEnabled()) {
            throw new Exception('Error: To create graphs in Piwik, please enable GD php extension (with Freetype support) in php.ini, and restart your web server.');
        }

        $useUnicodeFont = array(
            'am', 'ar', 'el', 'fa', 'fi', 'he', 'ja', 'ka', 'ko', 'te', 'th', 'zh-cn', 'zh-tw',
        );
        $languageLoaded = Piwik_Translate::getInstance()->getLanguageLoaded();
        $font = self::getFontPath(self::DEFAULT_FONT);
        if (in_array($languageLoaded, $useUnicodeFont)) {
            $unicodeFontPath = self::getFontPath(self::UNICODE_FONT);
            $font = file_exists($unicodeFontPath) ? $unicodeFontPath : $font;
        }

        // save original GET to reset after processing. Important for API-in-API-call
        $savedGET = $_GET;

        try {
            $apiParameters = array();
            if (!empty($idGoal)) {
                $apiParameters = array('idGoal' => $idGoal);
            }
            // Fetch the metadata for given api-action
            $metadata = Piwik_API_API::getInstance()->getMetadata(
                $idSite, $apiModule, $apiAction, $apiParameters, $languageLoaded, $period, $date,
                $hideMetricsDoc = false, $showSubtableReports = true);
            if (!$metadata) {
                throw new Exception('Invalid API Module and/or API Action');
            }

            $metadata = $metadata[0];
            $reportHasDimension = !empty($metadata['dimension']);
            $constantRowsCount = !empty($metadata['constantRowsCount']);

            $isMultiplePeriod = Piwik_Archive::isMultiplePeriod($date, $period);
            if (!$reportHasDimension && !$isMultiplePeriod) {
                throw new Exception('The graph cannot be drawn for this combination of \'date\' and \'period\' parameters.');
            }

            if (empty($legendFontSize)) {
                $legendFontSize = (int)$fontSize + self::DEFAULT_LEGEND_FONT_SIZE_OFFSET;
            }

            if (empty($graphType)) {
                if ($isMultiplePeriod) {
                    $graphType = Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_BASIC_LINE;
                } else {
                    if ($constantRowsCount) {
                        $graphType = Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_VERTICAL_BAR;
                    } else {
                        $graphType = Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_HORIZONTAL_BAR;
                    }
                }

                $reportUniqueId = $metadata['uniqueId'];
                if (isset(self::$DEFAULT_GRAPH_TYPE_OVERRIDE[$reportUniqueId][$isMultiplePeriod])) {
                    $graphType = self::$DEFAULT_GRAPH_TYPE_OVERRIDE[$reportUniqueId][$isMultiplePeriod];
                }
            } else {
                $availableGraphTypes = Piwik_ImageGraph_StaticGraph::getAvailableStaticGraphTypes();
                if (!in_array($graphType, $availableGraphTypes)) {
                    throw new Exception(
                        Piwik_TranslateException(
                            'General_ExceptionInvalidStaticGraphType',
                            array($graphType, implode(', ', $availableGraphTypes))
                        )
                    );
                }
            }

            $width = (int)$width;
            $height = (int)$height;
            if (empty($width)) {
                $width = self::$DEFAULT_PARAMETERS[$graphType][self::WIDTH_KEY];
            }
            if (empty($height)) {
                $height = self::$DEFAULT_PARAMETERS[$graphType][self::HEIGHT_KEY];
            }

            // Cap width and height to a safe amount
            $width = min($width, self::MAX_WIDTH);
            $height = min($height, self::MAX_HEIGHT);

            $reportColumns = array_merge(
                !empty($metadata['metrics']) ? $metadata['metrics'] : array(),
                !empty($metadata['processedMetrics']) ? $metadata['processedMetrics'] : array(),
                !empty($metadata['metricsGoal']) ? $metadata['metricsGoal'] : array(),
                !empty($metadata['processedMetricsGoal']) ? $metadata['processedMetricsGoal'] : array()
            );

            $ordinateColumns = array();
            if (empty($columns)) {
                $ordinateColumns[] =
                    empty($reportColumns[self::DEFAULT_ORDINATE_METRIC]) ? key($metadata['metrics']) : self::DEFAULT_ORDINATE_METRIC;
            } else {
                $ordinateColumns = explode(',', $columns);
                foreach ($ordinateColumns as $column) {
                    if (empty($reportColumns[$column])) {
                        throw new Exception(
                            Piwik_Translate(
                                'ImageGraph_ColumnOrdinateMissing',
                                array($column, implode(',', array_keys($reportColumns)))
                            )
                        );
                    }
                }
            }

            $ordinateLabels = array();
            foreach ($ordinateColumns as $column) {
                $ordinateLabels[$column] = $reportColumns[$column];
            }

            // sort and truncate filters
            $defaultFilterTruncate = self::$DEFAULT_PARAMETERS[$graphType][self::TRUNCATE_KEY];
            switch ($graphType) {
                case Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_3D_PIE:
                case Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_BASIC_PIE:

                    if (count($ordinateColumns) > 1) {
                        // pChart doesn't support multiple series on pie charts
                        throw new Exception("Pie charts do not currently support multiple series");
                    }

                    $_GET['filter_sort_column'] = reset($ordinateColumns);
                    $this->setFilterTruncate($defaultFilterTruncate);
                    break;

                case Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_VERTICAL_BAR:
                case Piwik_ImageGraph_StaticGraph::GRAPH_TYPE_BASIC_LINE:

                    if (!$isMultiplePeriod && !$constantRowsCount) {
                        $this->setFilterTruncate($defaultFilterTruncate);
                    }
                    break;
            }

            $ordinateLogos = array();

            // row evolutions
            if ($isMultiplePeriod && $reportHasDimension) {
                $plottedMetric = reset($ordinateColumns);

                // when no labels are specified, getRowEvolution returns the top N=filter_limit row evolutions
                // rows are sorted using filter_sort_column (see Piwik_API_DataTableGenericFilter for more info)
                if (!$labels) {
                    $savedFilterSortColumnValue = Piwik_Common::getRequestVar('filter_sort_column', '');
                    $_GET['filter_sort_column'] = $plottedMetric;

                    $savedFilterLimitValue = Piwik_Common::getRequestVar('filter_limit', -1, 'int');
                    if ($savedFilterLimitValue == -1 || $savedFilterLimitValue > self::MAX_NB_ROW_LABELS) {
                        $_GET['filter_limit'] = self::DEFAULT_NB_ROW_EVOLUTIONS;
                    }
                }

                $processedReport = Piwik_API_API::getInstance()->getRowEvolution(
                    $idSite,
                    $period,
                    $date,
                    $apiModule,
                    $apiAction,
                    $labels,
                    $segment = false,
                    $plottedMetric,
                    $languageLoaded,
                    $idGoal,
                    $legendAppendMetric,
                    $labelUseAbsoluteUrl = false
                );

                //@review this test will need to be updated after evaluating the @review comment in API/API.php
                if (!$processedReport) {
                    throw new Exception(Piwik_Translate('General_NoDataForGraph_js'));
                }

                // restoring generic filter parameters
                if (!$labels) {
                    $_GET['filter_sort_column'] = $savedFilterSortColumnValue;
                    if ($savedFilterLimitValue != -1) {
                        $_GET['filter_limit'] = $savedFilterLimitValue;
                    }
                }

                // retrieve metric names & labels
                $metrics = $processedReport['metadata']['metrics'];
                $ordinateLabels = array();

                // getRowEvolution returned more than one label
                if (!array_key_exists($plottedMetric, $metrics)) {
                    $ordinateColumns = array();
                    $i = 0;
                    foreach ($metrics as $metric => $info) {
                        $ordinateColumn = $plottedMetric . '_' . $i++;
                        $ordinateColumns[] = $metric;
                        $ordinateLabels[$ordinateColumn] = $info['name'];

                        if (isset($info['logo'])) {
                            $ordinateLogo = $info['logo'];

                            // @review pChart does not support gifs in graph legends, would it be possible to convert all plugin pictures (cookie.gif, flash.gif, ..) to png files?
                            if (!strstr($ordinateLogo, '.gif')) {
                                $absoluteLogoPath = self::getAbsoluteLogoPath($ordinateLogo);
                                if (file_exists($absoluteLogoPath)) {
                                    $ordinateLogos[$ordinateColumn] = $absoluteLogoPath;
                                }
                            }
                        }
                    }
                } else {
                    $ordinateLabels[$plottedMetric] = $processedReport['label'] . ' (' . $metrics[$plottedMetric]['name'] . ')';
                }
            } else {
                $processedReport = Piwik_API_API::getInstance()->getProcessedReport(
                    $idSite,
                    $period,
                    $date,
                    $apiModule,
                    $apiAction,
                    $segment = false,
                    $apiParameters = false,
                    $idGoal,
                    $languageLoaded,
                    $showTimer = true,
                    $hideMetricsDoc = false,
                    $idSubtable,
                    $showRawMetrics = false
                );
            }
            // prepare abscissa and ordinate series
            $abscissaSeries = array();
            $abscissaLogos = array();
            $ordinateSeries = array();
            $reportData = $processedReport['reportData'];
            $hasData = false;
            $hasNonZeroValue = false;

            if (!$isMultiplePeriod) {
                $reportMetadata = $processedReport['reportMetadata']->getRows();

                $i = 0;
                // $reportData instanceof Piwik_DataTable
                foreach ($reportData->getRows() as $row) // Piwik_DataTable_Row[]
                {
                    // $row instanceof Piwik_DataTable_Row
                    $rowData = $row->getColumns(); // Associative Array
                    $abscissaSeries[] = Piwik_Common::unsanitizeInputValue($rowData['label']);

                    foreach ($ordinateColumns as $column) {
                        $parsedOrdinateValue = $this->parseOrdinateValue($rowData[$column]);
                        $hasData = true;

                        if ($parsedOrdinateValue != 0) {
                            $hasNonZeroValue = true;
                        }
                        $ordinateSeries[$column][] = $parsedOrdinateValue;
                    }

                    if (isset($reportMetadata[$i])) {
                        $rowMetadata = $reportMetadata[$i]->getColumns();
                        if (isset($rowMetadata['logo'])) {
                            $absoluteLogoPath = self::getAbsoluteLogoPath($rowMetadata['logo']);
                            if (file_exists($absoluteLogoPath)) {
                                $abscissaLogos[$i] = $absoluteLogoPath;
                            }
                        }
                    }
                    $i++;
                }
            } else // if the report has no dimension we have multiple reports each with only one row within the reportData
            {
                // $periodsData instanceof Piwik_DataTable_Simple[]
                $periodsData = array_values($reportData->getArray());
                $periodsCount = count($periodsData);

                for ($i = 0; $i < $periodsCount; $i++) {
                    // $periodsData[$i] instanceof Piwik_DataTable_Simple
                    // $rows instanceof Piwik_DataTable_Row[]
                    if (empty($periodsData[$i])) {
                        continue;
                    }
                    $rows = $periodsData[$i]->getRows();

                    if (array_key_exists(0, $rows)) {
                        $rowData = $rows[0]->getColumns(); // associative Array

                        foreach ($ordinateColumns as $column) {
                            $ordinateValue = $rowData[$column];
                            $parsedOrdinateValue = $this->parseOrdinateValue($ordinateValue);

                            $hasData = true;

                            if (!empty($parsedOrdinateValue)) {
                                $hasNonZeroValue = true;
                            }

                            $ordinateSeries[$column][] = $parsedOrdinateValue;
                        }

                    } else {
                        foreach ($ordinateColumns as $column) {
                            $ordinateSeries[$column][] = 0;
                        }
                    }

                    $rowId = $periodsData[$i]->metadata['period']->getLocalizedShortString();
                    $abscissaSeries[] = Piwik_Common::unsanitizeInputValue($rowId);
                }
            }

            if (!$hasData || !$hasNonZeroValue) {
                throw new Exception(Piwik_Translate('General_NoDataForGraph_js'));
            }

            //Setup the graph
            $graph = Piwik_ImageGraph_StaticGraph::factory($graphType);
            $graph->setWidth($width);
            $graph->setHeight($height);
            $graph->setFont($font);
            $graph->setFontSize($fontSize);
            $graph->setLegendFontSize($legendFontSize);
            $graph->setOrdinateLabels($ordinateLabels);
            $graph->setShowLegend($showLegend);
            $graph->setAliasedGraph($aliasedGraph);
            $graph->setAbscissaSeries($abscissaSeries);
            $graph->setAbscissaLogos($abscissaLogos);
            $graph->setOrdinateSeries($ordinateSeries);
            $graph->setOrdinateLogos($ordinateLogos);
            $graph->setColors(!empty($colors) ? explode(',', $colors) : array());
            if ($period == 'day') {
                $graph->setForceSkippedLabels(6);
            }

            // render graph
            $graph->renderGraph();

        } catch (Exception $e) {

            $graph = new Piwik_ImageGraph_StaticGraph_Exception();
            $graph->setWidth($width);
            $graph->setHeight($height);
            $graph->setFont($font);
            $graph->setFontSize($fontSize);
            $graph->setException($e);
            $graph->renderGraph();
        }

        // restoring get parameters
        $_GET = $savedGET;

        switch ($outputType) {
            case self::GRAPH_OUTPUT_FILE:
                if ($idGoal != '') {
                    $idGoal = '_' . $idGoal;
                }
                $fileName = self::$DEFAULT_PARAMETERS[$graphType][self::FILENAME_KEY] . '_' . $apiModule . '_' . $apiAction . $idGoal . ' ' . str_replace(',', '-', $date) . ' ' . $idSite . '.png';
                $fileName = str_replace(array(' ', '/'), '_', $fileName);

                if (!Piwik_Common::isValidFilename($fileName)) {
                    throw new Exception('Error: Image graph filename ' . $fileName . ' is not valid.');
                }

                return $graph->sendToDisk($fileName);

            case self::GRAPH_OUTPUT_PHP:
                return $graph->getRenderedImage();

            case self::GRAPH_OUTPUT_INLINE:
            default:
                $graph->sendToBrowser();
                exit;
        }
    }

    private function setFilterTruncate($default)
    {
        $_GET['filter_truncate'] = Piwik_Common::getRequestVar('filter_truncate', $default, 'int');
    }

    private static function parseOrdinateValue($ordinateValue)
    {
        $ordinateValue = @str_replace(',', '.', $ordinateValue);

        // convert hh:mm:ss formatted time values to number of seconds
        if (preg_match('/([0-9]{1,2}):([0-9]{1,2}):([0-9]{1,2})/', $ordinateValue, $matches)) {
            $hour = $matches[1];
            $min = $matches[2];
            $sec = $matches[3];

            $ordinateValue = ($hour * 3600) + ($min * 60) + $sec;
        }

        // OK, only numbers from here please (strip out currency sign)
        $ordinateValue = preg_replace('/[^0-9.]/', '', $ordinateValue);
        return $ordinateValue;
    }

    private static function getFontPath($font)
    {
        return PIWIK_INCLUDE_PATH . self::FONT_DIR . $font;
    }

    protected static function getAbsoluteLogoPath($relativeLogoPath)
    {
        return PIWIK_INCLUDE_PATH . '/' . $relativeLogoPath;
    }
}
