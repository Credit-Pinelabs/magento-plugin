# Magento 2.4 + PinePG Plugin Docker Image
# Based on Bitnami Magento (available on Docker Hub)
FROM bitnami/magento:2.4.7

# Switch to root to copy files
USER root

# Copy the PinePG Magento plugin into the Magento code directory
COPY PinePGGateway/ /opt/bitnami/magento/app/code/Pinelabs/PinePGGateway/

# Enable the module
RUN cd /opt/bitnami/magento && \
    php bin/magento module:enable Pinelabs_PinePGGateway && \
    php bin/magento setup:upgrade && \
    php bin/magento setup:di:compile && \
    chown -R 1001:1001 /opt/bitnami/magento

USER 1001