import { Box, Text, Title, Badge, Image } from '@mantine/core';
import { useWindow } from './WindowContext';
import { useBadges } from './BadgeContext';
import { useState, useCallback, useMemo, useEffect, useRef } from 'react';
import { DragDropContext, Droppable, Draggable } from '@hello-pangea/dnd';
import {
    IconFolder,
    IconSettings,
    IconTerminal2,
    IconBrandDocker,
    IconActivity,
    IconDisc,
    IconShield,
    IconRefresh,
    IconApps,
    IconLifebuoy,
    IconCloudUpload,
    IconFileText,
    IconTransfer,
} from '@tabler/icons-react';
import { useIsMobile } from './useIsMobile';

// Map icon name strings to Tabler React components
const ICON_MAP = {
    IconFolder,
    IconSettings,
    IconTerminal2,
    IconBrandDocker,
    IconActivity,
    IconDisc,
    IconShield,
    IconRefresh,
    IconApps,
    IconLifebuoy,
    IconCloudUpload,
    IconFileText,
    IconTransfer,
};

export function DesktopIcons({ apps = [], onIconPositionChange }) {
    const { windows, openWindow, focusWindow } = useWindow();
    const { getBadge } = useBadges();
    const isMobile = useIsMobile();
    const [isDragging, setIsDragging] = useState(false);
    const containerRef = useRef(null);

    // Local state to track order - initialized from apps prop
    const [iconOrder, setIconOrder] = useState([]);

    // Initialize order from apps, and append any new apps that aren't in the order yet
    useEffect(() => {
        if (apps.length === 0) return;

        setIconOrder((prev) => {
            if (prev.length === 0) {
                const sortedApps = [...apps].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
                return sortedApps.map(app => app.desktopAppId || app.id);
            }

            const existingIds = new Set(prev.map(String));
            const newIds = apps
                .map(app => String(app.desktopAppId || app.id))
                .filter(id => !existingIds.has(id));

            return newIds.length > 0 ? [...prev, ...newIds] : prev;
        });
    }, [apps]);

    // Handle double click to open window
    const handleDoubleClick = useCallback((app) => {
        const existingWindow = windows.find(w => w.appId === app.id && !w.minimized);
        if (existingWindow) {
            focusWindow(existingWindow.id);
        } else {
            const IconComponent = ICON_MAP[app.iconName] || IconFolder;
            openWindow(app.id, app.name, IconComponent);
        }
    }, [windows, openWindow, focusWindow]);

    // Handle single click to open window
    const handleClick = useCallback((app) => {
        if (app.type === 'url' && app.url) {
            window.open(app.url, '_blank');
            return;
        }
        const IconComponent = ICON_MAP[app.iconName] || IconFolder;
        openWindow(app.id, app.name, IconComponent);
    }, [openWindow]);

    // Handle drag start
    const handleDragStart = useCallback(() => {
        setIsDragging(true);
    }, []);

    // Handle drag end - save new order to backend
    const handleDragEnd = useCallback((result) => {
        setIsDragging(false);

        const { destination, source, draggableId } = result;

        // Dropped outside the list
        if (!destination) {
            return;
        }

        // No change in position
        if (destination.index === source.index) {
            return;
        }

        // Reorder locally first for instant feedback
        const newOrder = Array.from(iconOrder);
        const [removed] = newOrder.splice(source.index, 1);
        newOrder.splice(destination.index, 0, removed);

        setIconOrder(newOrder);

        // Prepare order data for backend
        const orders = newOrder.map((appId, index) => ({
            desktop_app_id: parseInt(appId),
            order: index,
        }));

        // Save to backend
        if (onIconPositionChange) {
            onIconPositionChange(orders);
        }
    }, [iconOrder, onIconPositionChange]);

    // Get apps in current order - only recalculate when iconOrder actually changes
    const displayApps = useMemo(() => {
        if (iconOrder.length === 0 && apps.length > 0) {
            // Initialize order from apps on first render
            const sortedApps = [...apps].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
            return sortedApps;
        }

        // Map order to apps - use loose equality since iconOrder stores strings
        return iconOrder
            .map(id => apps.find(app => String(app.desktopAppId || app.id) === String(id)))
            .filter(Boolean);
    }, [iconOrder, apps]);

    return (
        <Box
            ref={containerRef}
            style={{
                position: 'absolute',
                top: 0,
                left: 0,
                right: 0,
                bottom: 0,
                padding: isMobile ? '12px' : '20px',
                display: 'flex',
                flexDirection: 'column',
                alignContent: 'flex-start',
                gap: isMobile ? '8px' : '16px',
                overflow: 'auto',
            }}
        >
            <Box style={{ padding: isMobile ? '0 4px 8px 4px' : '0 4px 16px 4px' }}>
                <Text size={isMobile ? 'sm' : 'lg'} fw={600} c="white" style={{ textShadow: '0 1px 2px rgba(0, 0, 0, 0.5)' }}>
                    Applications
                </Text>
                {!isMobile && (
                    <Text size="xs" c="dimmed">
                        Drag and drop to reorganize
                    </Text>
                )}
            </Box>
            <DragDropContext onDragStart={handleDragStart} onDragEnd={handleDragEnd}>
                <Droppable droppableId="desktop-icons" direction="horizontal">
                    {(provided) => (
                        <Box
                            ref={provided.innerRef}
                            {...provided.droppableProps}
                            style={{
                                display: 'flex',
                                flexWrap: 'wrap',
                                alignContent: 'flex-start',
                                gap: isMobile ? '4px' : '16px',
                                width: '100%',
                                height: '100%',
                            }}
                        >
                            {displayApps.map((app, index) => {
                                return (
                                    <Draggable
                                        key={app.id}
                                        draggableId={String(app.desktopAppId || app.id)}
                                        index={index}
                                    >
                                        {(provided, snapshot) => (
                                            <Box
                                                ref={provided.innerRef}
                                                {...provided.draggableProps}
                                                {...provided.dragHandleProps}
                                                style={{
                                                    ...provided.draggableProps.style,
                                                    display: 'flex',
                                                    flexDirection: 'column',
                                                    alignItems: 'center',
                                                    gap: '8px',
                                                    padding: '8px',
                                                    borderRadius: '8px',
                                                    cursor: snapshot.isDragging ? 'grabbing' : 'pointer',
                                                    opacity: snapshot.isDragging ? 0.8 : 1,
                                                    zIndex: snapshot.isDragging ? 1000 : 1,
                                                }}
                                                onDoubleClick={() => handleDoubleClick(app)}
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    handleClick(app);
                                                }}
                                                className="desktop-icon"
                                            >
                                                <Box
                                                    style={{
                                                        width: isMobile ? '52px' : '64px',
                                                        height: isMobile ? '52px' : '64px',
                                                        borderRadius: isMobile ? '12px' : '16px',
                                                        backgroundColor: app.color,
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        justifyContent: 'center',
                                                        boxShadow: snapshot.isDragging
                                                            ? '0 8px 24px rgba(0, 0, 0, 0.4)'
                                                            : '0 4px 12px rgba(0, 0, 0, 0.3)',
                                                        position: 'relative',
                                                    }}
                                                >
                                                {app.type === 'url' && app.iconPath ? (
                                                    <Image
                                                        src={app.iconPath}
                                                        w={40}
                                                        h={40}
                                                        fit="contain"
                                                        fallbackSrc="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='1.5'%3E%3Crect x='3' y='3' width='18' height='18' rx='2'/%3E%3Cpath d='m9 12 2 2 4-4'/%3E%3C/svg%3E"
                                                    />
                                                ) : (() => {
                                                    const IconComponent = ICON_MAP[app.iconName] || IconFolder;
                                                     return <IconComponent size={isMobile ? 26 : 32} color="white" />;
                                                })()}
                                                {(() => {
                                                    const badgeCount = getBadge(app.identifier || app.id);
                                                    if (badgeCount > 0) {
                                                        return (
                                                            <Badge
                                                                size="sm"
                                                                variant="filled"
                                                                color="red"
                                                                style={{
                                                                    position: 'absolute',
                                                                    top: '-10px',
                                                                    right: '-10px',
                                                                    minWidth: '24px',
                                                                    height: '24px',
                                                                    padding: '0 6px',
                                                                    fontSize: '12px',
                                                                    fontWeight: 600,
                                                                    borderRadius: '12px',
                                                                    border: '2px solid var(--mantine-color-dark-9)',
                                                                }}
                                                            >
                                                                {badgeCount > 99 ? '99+' : badgeCount}
                                                            </Badge>
                                                        );
                                                    }
                                                    return null;
                                                })()}
                                                </Box>
                                                <Text
                                                    size={isMobile ? 'xs' : 'sm'}
                                                    c="white"
                                                    style={{
                                                        textAlign: 'center',
                                                        textShadow: '0 1px 2px rgba(0, 0, 0, 0.5)',
                                                        fontWeight: 500,
                                                        lineHeight: 1.2,
                                                        maxWidth: isMobile ? '70px' : '90px',
                                                        overflow: 'hidden',
                                                        textOverflow: 'ellipsis',
                                                        whiteSpace: 'nowrap',
                                                    }}
                                                >
                                                    {app.name}
                                                </Text>
                                            </Box>
                                        )}
                                    </Draggable>
                                );
                            })}
                            {provided.placeholder}
                        </Box>
                    )}
                </Droppable>
            </DragDropContext>
        </Box>
    );
}
