import { useState } from 'react';
import { Box, Text, useMantineTheme } from '@mantine/core';
import {
    IconSettings,
    IconNetwork,
    IconPalette,
    IconUser,
    IconShield,
    IconBell,
    IconCloud,
    IconWifi,
} from '@tabler/icons-react';
import { GeneralTab } from './Settings/GeneralTab';
import { NetworkTab } from './Settings/NetworkTab';
import { DynDnsTab } from './Settings/DynDnsTab';
import { UpnpTab } from './Settings/UpnpTab';

const tabs = [
    { id: 'general', label: 'General', icon: IconSettings },
    { id: 'network', label: 'Network', icon: IconNetwork },
    { id: 'upnp', label: 'UPNP', icon: IconWifi },
    { id: 'dyndns', label: 'DynDNS', icon: IconCloud },
    { id: 'appearance', label: 'Appearance', icon: IconPalette },
    { id: 'account', label: 'Account', icon: IconUser },
    { id: 'security', label: 'Security', icon: IconShield },
    { id: 'notifications', label: 'Notifications', icon: IconBell },
];

export function SettingsAppContent() {
    const [activeTab, setActiveTab] = useState('general');
    const theme = useMantineTheme();

    const renderTabContent = () => {
        switch (activeTab) {
            case 'general':
                return <GeneralTab />;
            case 'network':
                return <NetworkTab />;
            case 'upnp':
                return <UpnpTab />;
            case 'dyndns':
                return <DynDnsTab />;
            case 'appearance':
                return <Text c="dimmed">Appearance settings will appear here.</Text>;
            case 'account':
                return <Text c="dimmed">Account settings will appear here.</Text>;
            case 'security':
                return <Text c="dimmed">Security settings will appear here.</Text>;
            case 'notifications':
                return <Text c="dimmed">Notification settings will appear here.</Text>;
            default:
                return <GeneralTab />;
        }
    };

    return (
        <Box style={{ display: 'flex', height: '100%' }}>
            <Box
                style={{
                    width: '220px',
                    minWidth: '220px',
                    backgroundColor: theme.colors.dark[5],
                    borderRight: `1px solid ${theme.colors.dark[4]}`,
                    padding: '12px 8px',
                    display: 'flex',
                    flexDirection: 'column',
                }}
            >
                <Text
                    size="xs"
                    fw={700}
                    c="dimmed"
                    mb="xs"
                    px="sm"
                    style={{ textTransform: 'uppercase', letterSpacing: '0.5px' }}
                >
                    Settings
                </Text>
                {tabs.map((tab) => (
                    <Box
                        key={tab.id}
                        onClick={() => setActiveTab(tab.id)}
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '10px',
                            padding: '10px 12px',
                            borderRadius: '8px',
                            cursor: 'pointer',
                            backgroundColor: activeTab === tab.id ? theme.colors.blue[6] : 'transparent',
                            color: activeTab === tab.id ? 'white' : theme.colors.gray[4],
                            transition: 'all 0.15s ease',
                            marginBottom: '2px',
                        }}
                    >
                        <tab.icon size={18} />
                        <Text size="sm" fw={activeTab === tab.id ? 600 : 400}>
                            {tab.label}
                        </Text>
                    </Box>
                ))}
            </Box>

            <Box
                style={{
                    flex: 1,
                    padding: '24px',
                    overflow: 'auto',
                    backgroundColor: theme.colors.dark[7],
                }}
            >
                {renderTabContent()}
            </Box>
        </Box>
    );
}
