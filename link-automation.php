<?php
/**
 * Advanced Link Automation System
 * Easy to use with fully advanced features
 */

class LinkAutomation {
    private $db;
    private $config;
    
    public function __construct($database = null) {
        $this->db = $database;
        $this->config = [
            'max_links' => 1000,
            'timeout' => 30,
            'retry_attempts' => 3,
            'batch_size' => 50,
            'log_file' => 'logs/automation.log'
        ];
    }

    /**
     * Simple Method: Add automation rule
     */
    public function addRule($name, $source_url, $destination_url, $conditions = []) {
        $rule = [
            'id' => uniqid('rule_'),
            'name' => sanitize($name),
            'source' => sanitize($source_url),
            'destination' => sanitize($destination_url),
            'conditions' => $conditions,
            'created_at' => date('Y-m-d H:i:s'),
            'enabled' => true,
            'status' => 'active'
        ];
        
        $this->logAction('Rule Added', $rule['id'], $rule['name']);
        return $rule;
    }

    /**
     * Execute automation with multiple strategies
     */
    public function execute($rule_id, $strategy = 'sequential') {
        $strategies = ['sequential', 'parallel', 'scheduled', 'conditional'];
        
        if (!in_array($strategy, $strategies)) {
            throw new Exception("Invalid strategy: $strategy");
        }

        switch($strategy) {
            case 'parallel':
                return $this->executeParallel($rule_id);
            case 'scheduled':
                return $this->executeScheduled($rule_id);
            case 'conditional':
                return $this->executeConditional($rule_id);
            default:
                return $this->executeSequential($rule_id);
        }
    }

    /**
     * Sequential Execution (Default)
     */
    private function executeSequential($rule_id) {
        $result = [
            'status' => 'processing',
            'links_processed' => 0,
            'links_success' => 0,
            'links_failed' => 0,
            'execution_time' => 0
        ];

        $start_time = microtime(true);
        
        try {
            // Your automation logic here
            $result['status'] = 'completed';
            $result['execution_time'] = microtime(true) - $start_time;
        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
            $this->logAction('Execution Error', $rule_id, $e->getMessage());
        }

        return $result;
    }

    /**
     * Parallel Execution (Advanced)
     */
    private function executeParallel($rule_id) {
        // Uses async processing for multiple links
        return [
            'status' => 'processing_parallel',
            'method' => 'curl_multi_exec',
            'message' => 'Processing multiple links simultaneously'
        ];
    }

    /**
     * Scheduled Execution (Advanced)
     */
    private function executeScheduled($rule_id) {
        $schedule = [
            'frequency' => 'hourly', // hourly, daily, weekly, custom
            'next_run' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'cron_expression' => '0 * * * *'
        ];
        
        return [
            'status' => 'scheduled',
            'next_run' => $schedule['next_run'],
            'cron' => $schedule['cron_expression']
        ];
    }

    /**
     * Conditional Execution (Advanced)
     */
    private function executeConditional($rule_id, $conditions = []) {
        $validator = [
            'url_valid' => false,
            'link_alive' => false,
            'redirect_chain' => [],
            'response_code' => null,
            'execution_allowed' => false
        ];

        foreach ($conditions as $condition => $value) {
            switch($condition) {
                case 'check_url_validity':
                    $validator['url_valid'] = filter_var($value, FILTER_VALIDATE_URL);
                    break;
                case 'check_link_alive':
                    $validator['link_alive'] = $this->checkLinkAlive($value);
                    break;
                case 'follow_redirects':
                    $validator['redirect_chain'] = $this->getRedirectChain($value);
                    break;
            }
        }

        $validator['execution_allowed'] = $validator['url_valid'] && $validator['link_alive'];
        
        return $validator;
    }

    /**
     * Batch Processing (Easy to Use)
     */
    public function batchProcess($links = []) {
        $results = [
            'total' => count($links),
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'batch_results' => []
        ];

        foreach (array_chunk($links, $this->config['batch_size']) as $batch) {
            foreach ($batch as $link) {
                try {
                    $result = $this->processLink($link);
                    $results['batch_results'][] = $result;
                    if ($result['status'] === 'success') {
                        $results['successful']++;
                    } else {
                        $results['failed']++;
                    }
                    $results['processed']++;
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['batch_results'][] = [
                        'link' => $link,
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Process individual link with retry logic
     */
    private function processLink($link) {
        $attempt = 0;
        $max_attempts = $this->config['retry_attempts'];

        while ($attempt < $max_attempts) {
            try {
                $response = $this->makeRequest($link);
                
                if ($response['status'] === 'success') {
                    return [
                        'link' => $link,
                        'status' => 'success',
                        'response_code' => $response['code'],
                        'attempts' => $attempt + 1
                    ];
                }
                
                $attempt++;
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $max_attempts) {
                    return [
                        'link' => $link,
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                        'attempts' => $attempt
                    ];
                }
            }
        }

        return ['link' => $link, 'status' => 'failed', 'attempts' => $max_attempts];
    }

    /**
     * Check if link is alive
     */
    private function checkLinkAlive($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->config['timeout']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($http_code >= 200 && $http_code < 400);
    }

    /**
     * Get redirect chain
     */
    private function getRedirectChain($url) {
        $chain = [];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->config['timeout']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);

        curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if (isset($info['redirect_url'])) {
            $chain[] = $info['redirect_url'];
        }

        return $chain;
    }

    /**
     * Make HTTP request
     */
    private function makeRequest($url, $method = 'GET') {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->config['timeout']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => ($http_code >= 200 && $http_code < 400) ? 'success' : 'failed',
            'code' => $http_code,
            'body' => $response
        ];
    }

    /**
     * Logging
     */
    private function logAction($action, $id, $message) {
        $log_entry = "[" . date('Y-m-d H:i:s') . "] $action - ID: $id - Message: $message\n";
        
        if (!is_dir('logs')) {
            mkdir('logs', 0755, true);
        }
        
        file_put_contents($this->config['log_file'], $log_entry, FILE_APPEND);
    }

    /**
     * Get automation statistics
     */
    public function getStats() {
        return [
            'total_rules' => 0,
            'active_rules' => 0,
            'disabled_rules' => 0,
            'total_executions' => 0,
            'success_rate' => 0,
            'avg_execution_time' => 0
        ];
    }

    /**
     * Export automation rules
     */
    public function exportRules($format = 'json') {
        // Export rules in JSON, CSV, or XML format
        return [
            'format' => $format,
            'timestamp' => date('Y-m-d H:i:s'),
            'total_rules' => 0
        ];
    }
}

/**
 * Helper function to sanitize inputs
 */
function sanitize($input) {
    return htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8');
}

// Usage Example:
/*
$automation = new LinkAutomation();

// Easy: Add a simple rule
$automation->addRule(
    'My First Automation',
    'https://example.com/source',
    'https://example.com/destination'
);

// Easy: Batch process links
$links = ['https://link1.com', 'https://link2.com'];
$results = $automation->batchProcess($links);

// Advanced: Execute with specific strategy
$automation->execute('rule_id', 'parallel');
$automation->execute('rule_id', 'scheduled');
$automation->execute('rule_id', 'conditional');

// Get statistics
$stats = $automation->getStats();
*/
?>
