const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
  ...defaultConfig,
  entry: {
    payxpert_cc: path.resolve(__dirname, 'assets/js/src/blocks/payxpert_cc.js'),
    payxpert_installment_x2: path.resolve(__dirname, 'assets/js/src/blocks/payxpert_installment_x2.js'),
    payxpert_installment_x3: path.resolve(__dirname, 'assets/js/src/blocks/payxpert_installment_x3.js'),
    payxpert_installment_x4: path.resolve(__dirname, 'assets/js/src/blocks/payxpert_installment_x4.js'),
  },
  output: {
    path: path.resolve(__dirname, 'assets/js/build/blocks'),
    filename: '[name].js',
  },
};
