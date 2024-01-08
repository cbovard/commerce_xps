# Commerce XPS Shipping

Provides XPS Shipping shipping rates for Drupal Commerce. This Module is loosely based on the USPS Shipping Module.

## Admin Setup

1. Install and enable the module here /admin/modules

2. Will need to sign up for an account here https://signup.xpsship.com/

   - This module is based on the https://xpsship.com/xps-custom-software-integrations/restful-api-integration/

3. After you sign up for an account. You need to sign into here https://xpsship.zendesk.com

4. Here is the Setup to access the REST API for the Module:

   - Add E-commerce Integration https://xpsship.zendesk.com/hc/en-us/articles/4401761648276-Custom-Integration-Options-ODBC-REST-API-and-Webhooks
   - Get your API Key and Customer ID https://xpsshipper.com/restapi/docs/v1-ecommerce/endpoints/overview/

5. Go to /admin/commerce/config/shipping-methods/add:
   - Select XPS Shipping as the Plugin
   - Enter the XPS API details
