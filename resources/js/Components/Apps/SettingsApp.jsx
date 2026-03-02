import { Box, Text, Stack, UnstyledButton, useMantineTheme } from '@mantine/core';
import { IconSettings, IconUser, IconBell, IconShield, IconWorld, IconPalette } from '@tabler/icons-react';
import { useState } from 'react';

const SETTINGS_TABS = [
    { id: 'general', label: 'General', icon: IconSettings },
    { id: 'network', label: 'Network', icon: IconWorld },
    { id: 'appearance', label: 'Appearance', icon: IconPalette },
    { id: 'account', label: 'Account', icon: IconUser },
    { id: 'security', label: 'Security', icon: IconShield },
    { id: 'notifications', label: 'Notifications', icon: IconBell },
];

export function SettingsAppContent() {
    const theme = useMantineTheme();
    const [activeTab, setActiveTab] = useState('general');

    return (
        <Box
            style={{
                display: 'flex',
                height: '100%',
                backgroundColor: theme.colors.dark[8],
            }}
        >
            {/* Left Sidebar with Tabs */}
            <Box
                style={{
                    width: '220px',
                    borderRight: `1px solid ${theme.colors.dark[5]}`,
                    padding: '16px 8px',
                    backgroundColor: theme.colors.dark[9],
                }}
            >
                <Stack gap={4}>
                    {SETTINGS_TABS.map((tab) => {
                        const isActive = activeTab === tab.id;
                        const Icon = tab.icon;

                        return (
                            <UnstyledButton
                                key={tab.id}
                                onClick={() => setActiveTab(tab.id)}
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: '12px',
                                    padding: '10px 12px',
                                    borderRadius: '8px',
                                    backgroundColor: isActive ? theme.colors.blue[7] : 'transparent',
                                    color: isActive ? 'white' : theme.colors.gray[4],
                                    transition: 'all 0.2s ease',
                                    width: '100%',
                                    textAlign: 'left',
                                }}
                                onMouseEnter={(e) => {
                                    if (!isActive) {
                                        e.currentTarget.style.backgroundColor = theme.colors.dark[6];
                                    }
                                }}
                                onMouseLeave={(e) => {
                                    if (!isActive) {
                                        e.currentTarget.style.backgroundColor = 'transparent';
                                    }
                                }}
                            >
                                <Icon size={20} />
                                <Text size="sm" fw={isActive ? 600 : 400}>
                                    {tab.label}
                                </Text>
                            </UnstyledButton>
                        );
                    })}
                </Stack>
            </Box>

            {/* Main Content Area */}
            <Box
                style={{
                    flex: 1,
                    padding: '24px',
                    overflow: 'auto',
                }}
            >
                {activeTab === 'general' && (
                    <Box>
                        <Text size="xl" fw={600} c="white" mb="md">
                            General Settings
                        </Text>
                        <Text c="dimmed">
                            General settings will appear here.
                        </Text>
                    </Box>
                )}
            </Box>
        </Box>
    );
}
