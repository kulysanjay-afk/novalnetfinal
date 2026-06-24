<?php
/**
 * This file is used for displaying the Google Pay button
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet
 * @license      https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */
namespace Novalnet\Providers\DataProvider;

use Novalnet\Helper\PaymentHelper;
use Novalnet\Services\PaymentService;
use Novalnet\Services\SettingsService;
use Plenty\Plugin\Templates\Twig;
use Plenty\Modules\Basket\Models\Basket;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;
use Plenty\Modules\Order\Shipping\Contracts\ShippingProfileRepositoryContract;
/**
 * Class NovalnetGooglePayButtonDataProvider
 *
 * @package Novalnet\Providers\DataProvider
 */
class NovalnetGooglePayButtonDataProvider
{


    use Loggable;
    /**
     * Display the Google Pay button
     *
     * @param Twig $twig
     * @param BasketRepositoryContract $basketRepository
     * @param CountryRepositoryContract $countryRepository
     * @param WebstoreHelper $webstoreHelper
     * @param Arguments $arg
     *
     * @return string
     */
    public function call(Twig $twig,
                         BasketRepositoryContract $basketRepository,
                         CountryRepositoryContract $countryRepository,
                         WebstoreHelper $webstoreHelper,
                         $arg)
    {
        $basket             = $basketRepository->load();
        $paymentHelper      = pluginApp(PaymentHelper::class);
        $sessionStorage     = pluginApp(FrontendSessionStorageFactoryContract::class);
        $paymentService     = pluginApp(PaymentService::class);
        $settingsService    = pluginApp(SettingsService::class);


        $this->getLogger(__METHOD__)->error('checklinetem', [
            '$nnn' => $basket ,
                                        
        ]);


        if($settingsService->getPaymentSettingsValue('payment_active', 'novalnet_googlepay') == true) {
            if(!empty($basket->basketAmount)) {
                $orderAmount = 0;
                /** @var \Plenty\Modules\Frontend\Services\VatService $vatService */
                $vatService = pluginApp(\Plenty\Modules\Frontend\Services\VatService::class);

                //we have to manipulate the basket because its stupid and doesnt know if its netto or gross
                if(!count($vatService->getCurrentTotalVats())) {
                    $basket->itemSum = $basket->itemSumNet;
                    $basket->shippingAmount = $basket->shippingAmountNet;
                    $basket->basketAmount = $basket->basketAmountNet;
                }
                // Get the order total basket amount
                $orderAmount = (string) $paymentHelper->convertAmountToSmallerUnit($basket->basketAmount);
            }
            $article_details = [];


            $shippingName = 'Shipping';

            $shippingProfileRepository = pluginApp(
                ShippingProfileRepositoryContract::class
            );
            
            $shippingProfiles =
                $shippingProfileRepository->all();
            
            foreach ($shippingProfiles as $profile) {
            
                if ($profile->id == $basket->shippingProfileId) {
            
                    $shippingName = $profile->name;
                    break;
                }
            }





            $itemRepository = pluginApp(ItemRepositoryContract::class);

            if (!empty($basket->basketItems)) {

                foreach ($basket->basketItems as $item) {

                    // Product
                    if (in_array(($item->itemType ?? 0), [1, 10])) {

                        $itemData = $itemRepository->show(
                            (int)$item->itemId
                        );

                        $productName = $itemData->texts[0]->name1 ?? '';

                        $article_details[] = array(
                            'label'  => $productName .
                                ' ( ' . (int)$item->quantity .
                                ' X €' . number_format($item->price, 2) . ' )',

                            'amount' => (string)$paymentHelper
                                ->convertAmountToSmallerUnit(
                                    $item->price * $item->quantity
                                ),

                            'type'   => 'SUBTOTAL',
                        );
                    }
                }
            }

            // Shipping
            if ($basket->shippingAmount > 0) {

                $article_details[] = array(
                    'label'  => 'Shipping',
                    'amount' => (string)$paymentHelper
                        ->convertAmountToSmallerUnit(
                            $basket->shippingAmount
                        ),
                    'type'   => 'SUBTOTAL',
                );
            }

            // Discount
            if ($basket->couponDiscount < 0) {

                $article_details[] = array(
                    'label'  => 'Discount (' .
                        $basket->couponCode . ')',

                    'amount' => (string)$paymentHelper
                    ->convertAmountToSmallerUnit(
                        $basket->couponDiscount
                    ),

                    'type'   => 'SUBTOTAL',
                );
            }
            

            $articleDetailsJson = json_encode($article_details);
                        
            // Get the Payment MOP Id
            $paymentMethodDetails = $paymentHelper->getPaymentMethodByKey('NOVALNET_GOOGLEPAY');
            // Get the order language
            $orderLang = strtoupper($sessionStorage->getLocaleSettings()->language);
            // Get the countryCode
            $billingAddress = $paymentHelper->getCustomerAddress((int) $basket->customerInvoiceAddressId);
            // Get the seller name from the shop configuaration
            $sellerName = $settingsService->getPaymentSettingsValue('business_name', 'novalnet_googlepay');
            // Required details for the Google Pay button
            $googlePayData = [
                                'clientKey'     => trim($settingsService->getPaymentSettingsValue('novalnet_client_key')),
                                'merchantId'    => $settingsService->getPaymentSettingsValue('payment_active', 'novalnet_googlepay'),
                                'sellerName'    => !empty($sellerName) ? $sellerName : $webstoreHelper->getCurrentWebstoreConfiguration()->name,
                                'article_details' => $articleDetailsJson,
                                'enforce'       => $settingsService->getPaymentSettingsValue('enforce', 'novalnet_googlepay'),
                                'buttonType'    => $settingsService->getPaymentSettingsValue('button_type', 'novalnet_googlepay'),
                                'buttonHeight'  => $settingsService->getPaymentSettingsValue('button_height', 'novalnet_googlepay'),
                                'testMode'      => ($settingsService->getPaymentSettingsValue('test_mode', 'novalnet_googlepay') == true) ? 'SANDBOX' : 'PRODUCTION'
                             ];
            // Render the Google Pay button
            return $twig->render('Novalnet::PaymentForm.NovalnetGooglePayButton',
                                        [
                                            'paymentMethodId'       => $paymentMethodDetails[0],
                                            'googlePayData'         => $googlePayData,
                                            'countryCode'           => !empty($countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2')) ? $countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2') : 'DE',
                                            'orderAmount'           => $orderAmount,
                                            'orderLang'             => $orderLang,
                                            'orderCurrency'         => $basket->currency,
                                            'nnPaymentProcessUrl'   => $paymentService->getProcessPaymentUrl()
                                        ]);
        } else {
            return '';
        }
    }
}
