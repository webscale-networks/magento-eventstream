# Webscale EventStream
Enables the setup and configuration of the Webscale EventStream extension.

## Installation
To install extension start with the following in magento root directory to add repository:
```console
composer config repositories.webscale-magento-eventstream git https://github.com/webscale-networks/magento-eventstream.git
```

To avoid issues with CI/CD and GitHub, add `"no-api": true` to the repository settings so it looks like this:
```console
"webscale-magento-eventstream": {
    "type": "git",
    "url": "https://github.com/webscale-networks/magento-eventstream.git",
    "no-api": true
}
```

Now require the extension itself:
```console
composer require webscale-networks/magento-eventstream
```

After composer installs the package run next Magento commands:

```console
php bin/magento module:enable Webscale_EventStream
php bin/magento setup:upgrade
bin/magento cache:clean
```

Once completed log in to the Magento admin panel and proceed to configuring the extension.

## Configuration

To enter the credentials open a browser and log in to the Magento admin. Next, navigate to:
```
Stores > Configuration > Webscale > EventStream
```

Enable the module by switching `Enabled` to `Yes`.

## Optional

### Debug Mode

You can also select `Enable Debug` under `Developer` section - this will result to more detailed server logs:
