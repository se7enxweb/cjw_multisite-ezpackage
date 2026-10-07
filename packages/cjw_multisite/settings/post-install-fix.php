<?php
// Post-install helpers of the CJW Multisite installer (cjw_multisite), generated
// from the 7x MultiSite installer's (sevenx_multisite) by renaming its functions
// (sevenx* -> cjw*, so both files can never meet as one declaration) and
// leaving out what only its demo content needs. The starter content steps are
// in cjw-starter-install.php.

if ( !function_exists( 'cjwSitePackageName' ) )
{
    /** The site package these settings files belong to: the directory above settings/. */
    function cjwSitePackageName()
    {
        return basename( dirname( __DIR__ ) );
    }
}

if ( !function_exists( 'cjwDemocontentPackageName' ) )
{
    /**
     * The content package the site package installs: the package it requires
     * whose name starts with cjw_multisite_democontent. Read from the site
     * package's own package.xml, so a renamed or repointed package needs no
     * change here; the name convention is only the fallback for a caller that
     * includes this file from somewhere else.
     */
    function cjwDemocontentPackageName()
    {
        $sitePackageName = cjwSitePackageName();
        $sitePackage = eZPackage::fetch( $sitePackageName, false, false, false );
        if ( $sitePackage instanceof eZPackage )
        {
            $dependencies = $sitePackage->attribute( 'dependencies' );
            $requires = isset( $dependencies['requires'] ) ? (array)$dependencies['requires'] : array();
            foreach ( $requires as $require )
            {
                if ( isset( $require['name'] ) && strpos( $require['name'], 'cjw_multisite_democontent' ) === 0 )
                    return $require['name'];
            }
        }
        return 'cjw_multisite_democontent';
    }
}

if ( !function_exists( 'cjwDemocontentObjectDir' ) )
{
    /** The ezcontentobject directory of the content package, wherever its repository is. */
    function cjwDemocontentObjectDir()
    {
        $name = cjwDemocontentPackageName();
        $package = eZPackage::fetch( $name, false, false, false );
        if ( $package instanceof eZPackage )
            return $package->path() . '/ezcontentobject';
        return eZSys::rootDir() . '/var/storage/packages/7x/' . $name . '/ezcontentobject';
    }
}

if ( !function_exists( 'cjwFixPackageNodesAndExplayouts' ) )
{
    function cjwFixPackageNodesAndExplayouts()
    {
        $db = eZDB::instance();

        $packageDir = cjwDemocontentObjectDir();
        $files = glob( $packageDir . '/object-cjw-starter-o-*.xml' );

        if ( !is_array( $files ) )
        {
            eZDebug::writeError( 'No package object files found', __FUNCTION__ );
            return false;
        }

        $nodeAssignments = array();
        $packageNodeMap = array();
        $packageObjectMap = array();

        foreach ( $files as $file )
        {
            $dom = new DOMDocument();
            if ( !@$dom->load( $file ) )
                continue;

            $objectNode = $dom->getElementsByTagNameNS( 'http://ez.no/ezobject', 'object' )->item( 0 );
            if ( !$objectNode )
                continue;

            $packageObjectID = (int)$objectNode->getAttribute( 'ezremote:id' );
            $objectRemoteID = $objectNode->getAttribute( 'remote_id' );

            $object = $objectRemoteID ? eZContentObject::fetchByRemoteID( $objectRemoteID ) : false;
            if ( !$object )
            {
                $object = eZContentObject::fetch( $packageObjectID );
            }
            if ( !$object )
                continue;

            $objectID = (int)$object->attribute( 'id' );
            $version = (int)$object->attribute( 'current_version' );
            if ( $version < 1 )
                continue;

            $packageObjectMap[$packageObjectID] = $objectID;

            $naListNode = $dom->getElementsByTagNameNS( 'http://ez.no/object/', 'node-assignment-list' )->item( 0 );
            if ( !$naListNode )
                continue;

            $naList = $naListNode->getElementsByTagName( 'node-assignment' );
            if ( $naList->length === 0 )
                $naList = $naListNode->getElementsByTagNameNS( 'http://ez.no/object/', 'node-assignment' );
            foreach ( $naList as $na )
            {
                $packageNodeId = (int)$na->getAttribute( 'node-id' );
                $nodeRemoteID = $na->getAttribute( 'remote-id' );
                $parentRemoteID = $na->getAttribute( 'parent-node-remote-id' );

                $nodeAssignments[$packageNodeId] = array(
                    'object_id' => $objectID,
                    'version' => $version,
                    'package_node_id' => $packageNodeId,
                    'node_remote_id' => $nodeRemoteID,
                    'parent_remote_id' => $parentRemoteID,
                    'sort_field' => eZContentObjectTreeNode::sortFieldID( $na->getAttribute( 'sort-field' ) ),
                    'sort_order' => (int)$na->getAttribute( 'sort-order' ),
                    'priority' => (int)$na->getAttribute( 'priority' ),
                    'is_main' => (int)$na->getAttribute( 'is-main-node' ),
                    'name' => $na->getAttribute( 'name' ),
                );
            }
        }

        eZDebug::writeNotice( 'Parsed ' . count( $nodeAssignments ) . ' node assignments from package XML', __FUNCTION__ );

        $existingNodes = $db->arrayQuery( 'SELECT remote_id, node_id FROM ezcontentobject_tree' );
        $remoteIdToNodeId = array();
        foreach ( $existingNodes as $row )
            $remoteIdToNodeId[$row['remote_id']] = (int)$row['node_id'];

        $resolveParentNodeId = function( $parentRemoteID, $remoteIdToNodeId )
        {
            if ( $parentRemoteID === '' )
                return 2;

            if ( isset( $remoteIdToNodeId[$parentRemoteID] ) )
                return $remoteIdToNodeId[$parentRemoteID];

            $parentNode = eZContentObjectTreeNode::fetchByRemoteID( $parentRemoteID );
            if ( $parentNode )
                return (int)$parentNode->attribute( 'node_id' );

            return false;
        };

        $createdCount = 0;
        $pass = 0;
        $maxPasses = 50;

        do
        {
            $progress = false;
            $pass++;

            foreach ( $nodeAssignments as $packageNodeId => $a )
            {
                if ( isset( $packageNodeMap[$packageNodeId] ) )
                    continue;

                if ( isset( $remoteIdToNodeId[$a['node_remote_id']] ) )
                {
                    $packageNodeMap[$packageNodeId] = $remoteIdToNodeId[$a['node_remote_id']];
                    continue;
                }

                $parentNodeID = $resolveParentNodeId( $a['parent_remote_id'], $remoteIdToNodeId );
                if ( $parentNodeID === false )
                    continue;

                $existingNode = eZContentObjectTreeNode::findNode( $parentNodeID, $a['object_id'], true );
                if ( $existingNode )
                {
                    $actualNodeId = (int)$existingNode->attribute( 'node_id' );
                    $remoteIdToNodeId[$a['node_remote_id']] = $actualNodeId;
                    $packageNodeMap[$packageNodeId] = $actualNodeId;
                    continue;
                }

                $nodeAssignment = eZNodeAssignment::create( array(
                    'contentobject_id' => $a['object_id'],
                    'contentobject_version' => $a['version'],
                    'parent_node' => $parentNodeID,
                    'is_main' => $a['is_main'],
                    'sort_field' => $a['sort_field'],
                    'sort_order' => $a['sort_order'],
                    'priority' => $a['priority'],
                    'parent_remote_id' => $a['node_remote_id'],
                ) );
                $nodeAssignment->store();

                $actualNodeId = eZContentOperationCollection::publishNode( $parentNodeID, $a['object_id'], $a['version'], false );
                if ( $actualNodeId )
                {
                    $actualNodeId = (int)$actualNodeId;
                    $remoteIdToNodeId[$a['node_remote_id']] = $actualNodeId;
                    $packageNodeMap[$packageNodeId] = $actualNodeId;
                    $createdCount++;
                    $progress = true;
                }
            }
        } while ( $progress && $pass < $maxPasses );

        eZDebug::writeNotice( "Created $createdCount missing tree nodes in $pass pass(es)", __FUNCTION__ );

        // No explayouts references to remap: the starter's layouts are written
        // after this step from the content package's cjw-explayouts.json, which
        // names its nodes by remote id (cjwSeedStarterLayouts() in
        // cjw-starter-install.php), and the menus of the user siteaccesses are
        // written by the installer (siteMenuINISettings()).


        // Reparse package eztags attributes and store them. The package installer
        // may not have linked tags for content classes with a subtree limit, or
        // may have linked them to the wrong object due to package/actual ID drift.
        cjwFixEzTagsFromPackage( $packageDir, $packageObjectMap );

        // Remap any numeric <embed object_id="..."/> or <embed-node node_id="..."/> 
        // references in ezxmltext fields from package IDs to installed IDs.
        cjwFixEmbeddedObjectIDs( $packageObjectMap, $packageNodeMap );

        // Everything above rewrote settings and content ids AFTER the package
        // installer had already populated caches. cjwFixMenuINIFiles() writes
        // a fresh menu.ini per siteaccess with this install's node ids, and the
        // remaps touch content and tags. Nothing else clears eZ's caches at the
        // end of an install - the installer clears only the explayouts resolver
        // cache - so the first page views were served from caches built before
        // these fixes and showed the previous install's node ids. The footer
        // menu is where this surfaced: its second entry pointed at whatever
        // content happened to hold the old node id.
        if ( class_exists( 'eZCache' ) )
        {
            eZCache::clearAll();
            eZDebug::writeNotice( 'Cleared all caches after the post-install fixes', __FUNCTION__ );
        }

        return true;
    }
}

if ( !function_exists( 'cjwClearLinksToContentNotInstalled' ) )
{
    /**
     * Switch off block links whose page the content package did not install.
     *
     * Title blocks in the seeded layouts can link their heading to a section
     * by path, relative to the site's PathPrefix. When that section is not
     * installed the heading would link to a 404. A path is kept when it resolves as it
     * is or below one of the site prefixes given; otherwise the link is emptied
     * and the block's use_link switched off, so the heading renders as text.
     * JSON-shaped and node links need nothing here: expLayoutsLinkParameter
     * already renders an unresolvable one as text.
     *
     * Runs after the prefixed URL aliases exist.
     *
     * @param array $prefixes url alias paths of the site root (starter)
     */
    function cjwClearLinksToContentNotInstalled( array $prefixes )
    {
        $db = eZDB::instance();
        $rows = $db->arrayQuery( "SELECT id, block_id, value FROM explayouts_block_parameter WHERE name = 'link'" );
        $cleared = 0;
        foreach ( $rows as $row )
        {
            $value = trim( (string)$row['value'] );
            if ( $value === '' || $value[0] !== '/' )
                continue;
            $path = trim( $value, '/' );
            if ( $path === '' )
                continue;

            $found = false;
            foreach ( array_merge( array( '' ), $prefixes ) as $prefix )
            {
                $prefix = trim( (string)$prefix, '/' );
                if ( eZURLAliasML::fetchNodeIDByPath( ( $prefix !== '' ? $prefix . '/' : '' ) . $path ) )
                {
                    $found = true;
                    break;
                }
            }
            if ( $found )
                continue;

            $db->query( "UPDATE explayouts_block_parameter SET value = '' WHERE id = " . (int)$row['id'] );
            $db->query( "UPDATE explayouts_block_parameter SET value = '0' WHERE name = 'use_link' AND block_id = " . (int)$row['block_id'] );
            $cleared++;
        }

        eZDebug::writeNotice( "Switched off $cleared block link(s) to pages this installation does not have", __FUNCTION__ );
        return true;
    }
}

if ( !function_exists( 'cjwRegenerateURLAliases' ) )
{
    function cjwRegenerateURLAliases()
    {
        $db = eZDB::instance();

        // The alias tables are deliberately not emptied first.
        //
        // updateSubTreePath() recreates a node's alias underneath its parent's
        // element, so it needs that element to exist. Emptying the tables
        // removes the root elements the content tree hangs from, and the walk
        // below then leaves most of the tree without an alias at all: after an
        // install only the media library, whose aliases are created when its
        // objects are published, still had any. Every page on the two sites
        // rendered a system url.
        //
        // Measured on an installed database: with the truncate, 85 alias rows
        // and no alias for either site subtree. Without it, the same walk takes
        // 85 rows to 282 and covers the media library and the site subtrees.
        // bin/php/updateniceurls.php --update-nodes, which does not empty
        // the tables either, produces the same result.
        // Select whole rows, not just ids: eZContentObjectTreeNode::fetch runs the
        // node through the prioritised-language filter, and by this point in an
        // install that filter is stale in-process - fetch returns null for nodes
        // that are plainly there, and the walk skipped them. On a single language
        // install that was 177 of 267 nodes, including the two site home nodes,
        // so their whole subtrees ended up with no alias at all and every page
        // below them answered 404.
        //
        // A node built straight from its row does the same job here:
        // updateSubTreePath only needs the row's own columns.
        // Node 1, the root, is its own parent and has no alias
        $rows = $db->arrayQuery( 'SELECT * FROM ezcontentobject_tree WHERE node_id <> 1 ORDER BY depth ASC, node_id ASC' );
        $count = 0;
        $changed = 0;
        $rebuilt = 0;
        foreach ( $rows as $row )
        {
            $node = eZContentObjectTreeNode::fetch( (int)$row['node_id'] );
            if ( !$node )
            {
                $node = new eZContentObjectTreeNode( $row );
                ++$rebuilt;
            }
            if ( $node->updateSubTreePath() )
                $changed++;
            $count++;
        }

        eZDebug::writeNotice( "Regenerated URL aliases for $count nodes, $changed changed", __FUNCTION__ );
        return true;
    }
}

if ( !function_exists( 'cjwFixEmbeddedObjectIDs' ) )
{
    function cjwFixEmbeddedObjectIDs( $packageObjectMap, $packageNodeMap )
    {
        $db = eZDB::instance();

        $rows = $db->arrayQuery( "
            SELECT a.id, a.version, a.data_text
            FROM ezcontentobject_attribute a
            JOIN ezcontentclass_attribute ca ON a.contentclassattribute_id = ca.id
            WHERE ca.data_type_string = 'ezxmltext'
              AND a.data_text LIKE '%<embed%'" );

        $fixed = 0;
        foreach ( $rows as $row )
        {
            $data = $row['data_text'];
            $newData = $data;

            if ( preg_match_all( '/<embed[^>]+object_id="(\d+)"/', $data, $m ) )
            {
                foreach ( $m[1] as $packageId )
                {
                    if ( isset( $packageObjectMap[(int)$packageId] ) )
                    {
                        $newData = preg_replace( '/(<embed[^>]*)object_id="' . (int)$packageId . '"/', '$1object_id="' . (int)$packageObjectMap[(int)$packageId] . '"', $newData, 1 );
                    }
                }
            }

            if ( preg_match_all( '/<embed-node[^>]+node_id="(\d+)"/', $data, $m ) )
            {
                foreach ( $m[1] as $packageNodeId )
                {
                    if ( isset( $packageNodeMap[(int)$packageNodeId] ) )
                    {
                        $newData = preg_replace( '/(<embed-node[^>]*)node_id="' . (int)$packageNodeId . '"/', '$1node_id="' . (int)$packageNodeMap[(int)$packageNodeId] . '"', $newData, 1 );
                    }
                }
            }

            if ( $newData !== $data )
            {
                $db->query( 'UPDATE ezcontentobject_attribute SET data_text = \'' . $db->escapeString( $newData ) . '\' WHERE id = ' . (int)$row['id'] . ' AND version = ' . (int)$row['version'] );
                $fixed++;
            }
        }

        eZDebug::writeNotice( "Remapped embedded object IDs in $fixed ezxmltext attributes", __FUNCTION__ );
    }
}

if ( !function_exists( 'cjwFixEzTagsFromPackage' ) )
{
    function cjwFixEzTagsFromPackage( $packageDir, $packageObjectMap )
    {
        $adminUser = eZUser::instance( 14 );
        if ( $adminUser )
            eZUser::setCurrentlyLoggedInUser( $adminUser, 14 );

        $db = eZDB::instance();

        $files = glob( $packageDir . '/object-cjw-starter-o-*.xml' );
        $fixed = 0;

        foreach ( $files as $file )
        {
            $dom = new DOMDocument();
            if ( !@$dom->load( $file ) )
                continue;

            $objectNode = $dom->getElementsByTagNameNS( 'http://ez.no/ezobject', 'object' )->item( 0 );
            if ( !$objectNode )
                continue;

            $packageObjectID = (int)$objectNode->getAttribute( 'ezremote:id' );
            $objectRemoteID = $objectNode->getAttribute( 'remote_id' );

            $objectID = isset( $packageObjectMap[$packageObjectID] ) ? $packageObjectMap[$packageObjectID] : false;
            if ( !$objectID && $objectRemoteID )
            {
                $object = eZContentObject::fetchByRemoteID( $objectRemoteID );
                if ( $object )
                    $objectID = (int)$object->attribute( 'id' );
            }
            if ( !$objectID )
                continue;

            $contentObject = eZContentObject::fetch( $objectID );
            if ( !$contentObject )
                continue;

            $version = (int)$contentObject->attribute( 'current_version' );
            if ( $version < 1 )
                continue;

            $attributes = $dom->getElementsByTagNameNS( 'http://ez.no/object/', 'attribute' );
            foreach ( $attributes as $attrNode )
            {
                if ( $attrNode->getAttribute( 'type' ) !== 'eztags' )
                    continue;

                $idString = '';
                $keywordString = '';
                $parentString = '';
                $localeString = '';

                foreach ( $attrNode->childNodes as $child )
                {
                    if ( $child->nodeType !== XML_ELEMENT_NODE )
                        continue;
                    $nodeName = $child->localName;
                    if ( $nodeName === 'id-string' )
                        $idString = $child->textContent;
                    else if ( $nodeName === 'keyword-string' )
                        $keywordString = $child->textContent;
                    else if ( $nodeName === 'parent-string' )
                        $parentString = $child->textContent;
                    else if ( $nodeName === 'locale-string' )
                        $localeString = $child->textContent;
                }

                if ( $keywordString === '' )
                    continue;

                $identifier = $attrNode->getAttribute( 'identifier' );
                if ( !$identifier )
                    $identifier = $attrNode->getAttributeNS( 'http://ez.no/ezobject', 'identifier' );

                $dataMap = $contentObject->fetchDataMap( $version );
                if ( !isset( $dataMap[$identifier] ) )
                    continue;

                $objectAttribute = $dataMap[$identifier];
                $eZTags = eZTags::createFromStrings( $objectAttribute, $idString, $keywordString, $parentString, $localeString );
                $objectAttribute->setContent( $eZTags );
                $eZTags->store( $objectAttribute );
                $fixed++;
            }
        }

        eZDebug::writeNotice( "Fixed $fixed eztags attributes from package XML", __FUNCTION__ );
    }
}

/**
 * Re-key class and class attribute name and description lists stored under a
 * number instead of a language code.
 *
 * eZSerializedObjectNameList keys each name by a locale. Created while no
 * content language exists - the setup wizard, before the site's languages are
 * registered - the locale was false, serialize() turned that key into 0 and
 * always-available into false: a:2:{i:0;s:12:"Publish date";...}. No language
 * lookup finds key 0, so the admin shows the name blank (class/view/1: the
 * folder's tags and publish_date), and the descriptions of the base classes
 * were left the same way.
 *
 * Each numeric entry moves to the class's own language (its initial language,
 * else $fallbackLocale) unless that language already has a non-empty value;
 * always-available then names an entry that exists. Every version is repaired
 * so a class edited later starts from the repaired lists. Idempotent.
 *
 * @return array('changed' => rows written, 'left' => rows still without a language)
 */
function cjwRepairClassNameLists( $fallbackLocale )
{
    $db = eZDB::instance();
    $locales = array();
    foreach ( $db->arrayQuery( 'SELECT id, locale FROM ezcontent_language' ) as $l )
        $locales[(int)$l['id']] = $l['locale'];
    $classLocale = array();
    foreach ( $db->arrayQuery( 'SELECT id, version, initial_language_id FROM ezcontentclass' ) as $c )
    {
        $lid = (int)$c['initial_language_id'] & ~1;
        $classLocale[(int)$c['id']] = isset( $locales[$lid] ) ? $locales[$lid] : $fallbackLocale;
    }

    $repair = function ( $raw, $locale )
    {
        $a = @unserialize( (string)$raw );
        if ( !is_array( $a ) )
            return null;
        $out = $a;
        foreach ( $a as $k => $v )
        {
            if ( !is_int( $k ) )
                continue;
            unset( $out[$k] );
            if ( !isset( $out[$locale] ) || ( trim( (string)$out[$locale] ) === '' && trim( (string)$v ) !== '' ) )
                $out[$locale] = $v;
        }
        $langs = array_values( array_filter( array_keys( $out ), function ( $k ) { return $k !== 'always-available'; } ) );
        if ( !$langs )
        {
            $out[$locale] = '';
            $langs = array( $locale );
        }
        $aa = isset( $out['always-available'] ) ? $out['always-available'] : false;
        if ( !$aa || !isset( $out[$aa] ) )
        {
            unset( $out['always-available'] );
            $out['always-available'] = isset( $out[$locale] ) ? $locale : $langs[0];
        }
        return $out === $a ? null : serialize( $out );
    };

    $changed = 0;
    $left = array();
    $tables = array(
        'ezcontentclass' => array( 'key' => 'id', 'class' => 'id' ),
        'ezcontentclass_attribute' => array( 'key' => 'id', 'class' => 'contentclass_id' ),
    );
    foreach ( $tables as $table => $t )
    {
        $rows = $db->arrayQuery( "SELECT {$t['key']} AS k, version, {$t['class']} AS class_id, serialized_name_list, serialized_description_list FROM $table" );
        foreach ( $rows as $r )
        {
            $locale = isset( $classLocale[(int)$r['class_id']] ) ? $classLocale[(int)$r['class_id']] : $fallbackLocale;
            $set = array();
            foreach ( array( 'serialized_name_list', 'serialized_description_list' ) as $col )
            {
                $new = $repair( $r[$col], $locale );
                if ( $new !== null )
                    $set[] = "$col = '" . $db->escapeString( $new ) . "'";
            }
            if ( $set )
            {
                $db->query( "UPDATE $table SET " . implode( ', ', $set ) . ' WHERE ' . $t['key'] . ' = ' . (int)$r['k'] . ' AND version = ' . (int)$r['version'] );
                $changed++;
            }
            $check = @unserialize( (string)$r['serialized_name_list'] );
            if ( !is_array( $check ) )
                $left[] = "$table:" . (int)$r['k'] . '/' . (int)$r['version'];
        }
    }
    eZDebug::writeNotice( "Repaired $changed class/attribute name lists", __FUNCTION__ );
    return array( 'changed' => $changed, 'left' => $left );
}
