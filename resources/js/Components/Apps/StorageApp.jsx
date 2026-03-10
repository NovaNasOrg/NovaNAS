import { useState } from 'react';
import { Box, Text, useMantineTheme } from '@mantine/core';
import {
    IconDisc,
    IconServer,
    IconStack2,
    IconHeart,
    IconApps,
} from '@tabler/icons-react';
import { DisksTab } from './Storage/DisksTab';
import { HealthTab } from './Storage/HealthTab';
import { PoolsTab } from './Storage/PoolsTab';
import { AppTab } from './Storage/AppTab';

const tabs = [
    { id: 'disks', label: 'Disks', icon: IconDisc },
    { id: 'health', label: 'Health', icon: IconHeart },
    { id: 'pools', label: 'Pools', icon: IconStack2 },
    { id: 'app', label: 'App', icon: IconApps },
];

export function StorageAppContent() {
    const [activeTab, setActiveTab] = useState('disks');
    const theme = useMantineTheme();

    const renderTabContent = () => {
        switch (activeTab) {
            case 'disks':
                return <DisksTab />;
            case 'health':
                return <HealthTab />;
            case 'pools':
                return <PoolsTab />;
            case 'app':
                return <AppTab />;
            default:
                return <DisksTab />;
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
                    Storage
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
