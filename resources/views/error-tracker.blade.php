@if(config('sorane.javascript_errors.enabled'))
<script>
/**
 * Sorane JavaScript Error Tracking
 * Version: 1.0.0
 * 
 * This script automatically captures JavaScript errors and sends them to Sorane.
 * It includes support for:
 * - Global error handling (window.onerror)
 * - Unhandled promise rejections
 * - Console error capturing (optional)
 * - Breadcrumbs for debugging context
 * - Automatic error deduplication
 */

(function() {
    'use strict';

    const config = {
        endpoint: '{{ route('sorane.javascript-errors.store') }}',
        enabled: {{ config('sorane.javascript_errors.enabled') ? 'true' : 'false' }},
        sampleRate: {{ config('sorane.javascript_errors.sample_rate', 1.0) }},
        captureConsoleErrors: {{ config('sorane.javascript_errors.capture_console_errors') ? 'true' : 'false' }},
        maxBreadcrumbs: {{ config('sorane.javascript_errors.max_breadcrumbs', 20) }},
        ignoredErrors: @json(config('sorane.javascript_errors.ignored_errors', [])),
        csrfToken: '{{ csrf_token() }}',
    };

    if (!config.enabled) {
        return;
    }

    // Breadcrumb storage
    let breadcrumbs = [];

    // Error deduplication
    const sentErrors = new Set();
    const ERROR_CACHE_SIZE = 50;

    /**
     * Add a breadcrumb for debugging context
     */
    function addBreadcrumb(category, message, data = {}) {
        breadcrumbs.push({
            timestamp: new Date().toISOString(),
            category: category,
            message: message,
            data: data
        });

        // Keep only the last N breadcrumbs
        if (breadcrumbs.length > config.maxBreadcrumbs) {
            breadcrumbs.shift();
        }
    }

    /**
     * Get browser information
     */
    function getBrowserInfo() {
        return {
            screen_width: window.screen?.width,
            screen_height: window.screen?.height,
            viewport_width: window.innerWidth,
            viewport_height: window.innerHeight,
            device_memory: navigator.deviceMemory,
            hardware_concurrency: navigator.hardwareConcurrency,
            connection_type: navigator.connection?.effectiveType,
        };
    }

    /**
     * Check if error should be ignored
     */
    function shouldIgnoreError(message) {
        if (!message) return true;
        
        const messageStr = String(message);
        
        return config.ignoredErrors.some(pattern => {
            return messageStr.toLowerCase().includes(pattern.toLowerCase());
        });
    }

    /**
     * Generate a unique key for error deduplication
     */
    function getErrorKey(message, filename, line, column) {
        return `${message}|${filename}|${line}|${column}`;
    }

    /**
     * Check if error was recently sent
     */
    function wasRecentlySent(errorKey) {
        if (sentErrors.has(errorKey)) {
            return true;
        }

        sentErrors.add(errorKey);

        // Prevent memory leak by limiting cache size
        if (sentErrors.size > ERROR_CACHE_SIZE) {
            const firstKey = sentErrors.values().next().value;
            sentErrors.delete(firstKey);
        }

        return false;
    }

    /**
     * Send error to Sorane
     */
    function sendError(errorData) {
        // Apply sample rate
        if (config.sampleRate < 1.0 && Math.random() > config.sampleRate) {
            return;
        }

        // Check if error should be ignored
        if (shouldIgnoreError(errorData.message)) {
            return;
        }

        // Check for deduplication
        const errorKey = getErrorKey(
            errorData.message,
            errorData.filename,
            errorData.line,
            errorData.column
        );

        if (wasRecentlySent(errorKey)) {
            return;
        }

        // Add breadcrumbs and browser info
        errorData.breadcrumbs = [...breadcrumbs];
        errorData.browser_info = getBrowserInfo();
        errorData.url = window.location.href;
        errorData.timestamp = new Date().toISOString();

        // Send to server
        fetch(config.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(errorData),
            // Use keepalive to ensure request completes even if page unloads
            keepalive: true
        }).catch(function(err) {
            // Silently fail - don't want error tracking to cause more errors
            console.warn('Failed to send error to Sorane:', err);
        });
    }

    /**
     * Global error handler
     */
    window.addEventListener('error', function(event) {
        sendError({
            message: event.message || 'Unknown error',
            filename: event.filename || '',
            line: event.lineno || 0,
            column: event.colno || 0,
            stack: event.error?.stack || '',
            type: event.error?.name || 'Error'
        });
    }, true);

    /**
     * Unhandled promise rejection handler
     */
    window.addEventListener('unhandledrejection', function(event) {
        const reason = event.reason;
        
        let message = 'Unhandled Promise Rejection';
        let stack = '';
        let type = 'UnhandledRejection';

        if (reason instanceof Error) {
            message = reason.message || message;
            stack = reason.stack || '';
            type = reason.name || type;
        } else if (typeof reason === 'string') {
            message = reason;
        } else if (reason && typeof reason === 'object') {
            try {
                message = JSON.stringify(reason);
            } catch (e) {
                message = String(reason);
            }
        }

        sendError({
            message: message,
            stack: stack,
            type: type,
            filename: '',
            line: 0,
            column: 0
        });
    });

    /**
     * Capture console errors (optional)
     */
    if (config.captureConsoleErrors) {
        const originalConsoleError = console.error;
        console.error = function() {
            // Call original console.error
            originalConsoleError.apply(console, arguments);

            // Capture the error
            const args = Array.from(arguments);
            const message = args.map(arg => {
                if (arg instanceof Error) {
                    return arg.message;
                }
                if (typeof arg === 'object') {
                    try {
                        return JSON.stringify(arg);
                    } catch (e) {
                        return String(arg);
                    }
                }
                return String(arg);
            }).join(' ');

            sendError({
                message: 'Console Error: ' + message,
                type: 'ConsoleError',
                stack: args[0] instanceof Error ? args[0].stack : '',
                filename: '',
                line: 0,
                column: 0
            });
        };
    }

    /**
     * Track navigation breadcrumbs
     */
    addBreadcrumb('navigation', 'Page loaded', {
        url: window.location.href
    });

    /**
     * Track clicks (helps debug user interactions before errors)
     */
    document.addEventListener('click', function(event) {
        const target = event.target;
        const tagName = target.tagName?.toLowerCase() || 'unknown';
        const id = target.id || '';
        const className = target.className || '';
        const text = target.textContent?.substring(0, 50) || '';

        addBreadcrumb('user', 'Click', {
            tag: tagName,
            id: id,
            class: className,
            text: text
        });
    }, true);

    /**
     * Track form submissions
     */
    document.addEventListener('submit', function(event) {
        const form = event.target;
        const action = form.action || '';
        const method = form.method || '';

        addBreadcrumb('user', 'Form submitted', {
            action: action,
            method: method
        });
    }, true);

    /**
     * Track AJAX requests
     */
    if (window.XMLHttpRequest) {
        const originalOpen = XMLHttpRequest.prototype.open;
        const originalSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function(method, url) {
            this._sorane_method = method;
            this._sorane_url = url;
            return originalOpen.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function() {
            const xhr = this;
            
            xhr.addEventListener('load', function() {
                addBreadcrumb('http', 'XHR completed', {
                    method: xhr._sorane_method,
                    url: xhr._sorane_url,
                    status: xhr.status
                });
            });

            xhr.addEventListener('error', function() {
                addBreadcrumb('http', 'XHR failed', {
                    method: xhr._sorane_method,
                    url: xhr._sorane_url
                });
            });

            return originalSend.apply(this, arguments);
        };
    }

    /**
     * Track fetch requests
     */
    if (window.fetch) {
        const originalFetch = window.fetch;
        
        window.fetch = function() {
            const url = arguments[0];
            const options = arguments[1] || {};
            const method = options.method || 'GET';

            return originalFetch.apply(this, arguments)
                .then(function(response) {
                    addBreadcrumb('http', 'Fetch completed', {
                        method: method,
                        url: url,
                        status: response.status
                    });
                    return response;
                })
                .catch(function(error) {
                    addBreadcrumb('http', 'Fetch failed', {
                        method: method,
                        url: url,
                        error: error.message
                    });
                    throw error;
                });
        };
    }

    // Expose API for manual error tracking
    window.Sorane = window.Sorane || {};
    window.Sorane.captureError = function(error, context) {
        sendError({
            message: error.message || String(error),
            stack: error.stack || '',
            type: error.name || 'ManualError',
            filename: '',
            line: 0,
            column: 0,
            context: context || {}
        });
    };

    window.Sorane.addBreadcrumb = addBreadcrumb;

})();
</script>
@endif
