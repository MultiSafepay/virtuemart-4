<?php declare(strict_types=1);

/**
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the MultiSafepay plugin
 * to newer versions in the future. If you wish to customize the plugin for your
 * needs please document your changes and make backups before you update.
 *
 * @author      MultiSafepay <integration@multisafepay.com>
 * @copyright   Copyright (c) MultiSafepay, Inc. (https://www.multisafepay.com)
 * @license     http://www.gnu.org/licenses/gpl-3.0.html
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
 * INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR
 * PURPOSE AND NON-INFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN
 * ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

defined('_JEXEC') or die();

$ref = $viewData['ref']; 
$payment_name = $viewData['payment_name']; 
$data = $viewData['data']; 


$html = '<table style="margin-top:10px;">' . "\n";
$html .= $ref->getHtmlRow('MULTISAFEPAY_PAYMENT_NAME', $payment_name, 'style="padding:8px;"');
$html .= $ref->getHtmlRow('MULTISAFEPAY_STATUS', $data['status'], 'style="padding:8px;"');

$html .= $ref->getHtmlRow('MULTISAFEPAY_PAYMENT_TRANSACTIONID', $data['transactionid'], 'style="padding:8px;"');
$html .= '</table>' . "\n";

echo $html; 
